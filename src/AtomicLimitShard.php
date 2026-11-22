<?php

namespace EasySwoole\AtomicLimit;

use Swoole\Atomic\Long;
use Swoole\Coroutine;
use Swoole\Table;

/** @internal */
final class AtomicLimitShard
{
    private const GC_BATCH_SIZE = 64;
    private const LEASE_WAIT_NS = 100000000;
    private const LEASE_DURATION_MS = 5000;
    private const LEASE_SEQUENCE_BITS = 20;

    private $buckets;
    private $rules;
    private $defaultQps;
    private $autoGc;
    private $gcIntervalNs;
    private $gcChangedAt;
    private $lease;
    private $lastLeaseToken;
    private $lastGcAt;
    private $scanStarted = false;
    private $scanInProgress = false;
    private $maxToken;

    public function __construct(
        int $maxToken,
        Long $defaultQps,
        Long $autoGc,
        Long $gcIntervalNs,
        Long $gcChangedAt
    ) {
        $this->autoGc = $autoGc;
        $this->defaultQps = $defaultQps;
        $this->gcIntervalNs = $gcIntervalNs;
        $this->gcChangedAt = $gcChangedAt;
        $this->lastGcAt = new Long(0);
        $this->maxToken = $maxToken;
        $this->lease = new Long(0);
        $this->lastLeaseToken = new Long(0);
        $this->buckets = $this->createTable($maxToken, [
            'tokens' => Table::TYPE_INT,
            'batchAt' => Table::TYPE_INT,
            'refillRate' => Table::TYPE_FLOAT,
            'rate' => Table::TYPE_FLOAT,
            'capacity' => Table::TYPE_INT,
            'sampleSecond' => Table::TYPE_INT,
            'currentCount' => Table::TYPE_INT,
            'previousCount' => Table::TYPE_INT,
            'olderCount' => Table::TYPE_INT,
        ]);
        $this->rules = $this->createTable($maxToken, [
            'rate' => Table::TYPE_FLOAT,
            'capacity' => Table::TYPE_INT,
        ]);
    }

    public function setRule(string $key, float $rate, int $capacity): bool
    {
        $lease = $this->tryAcquireLease();
        if ($lease === null) {
            return false;
        }

        try {
            $rule = ['rate' => $rate, 'capacity' => $capacity];
            if (!$this->rules->exists($key) && $this->rules->count() >= $this->maxToken) {
                return false;
            }
            $now = $this->now();
            if (!$this->settleExistingBucket($key, $now) || !@$this->rules->set($key, $rule)) {
                return false;
            }
            $this->applyRule($key, $rule, $now);
            return true;
        } finally {
            $this->releaseLease($lease);
        }
    }

    public function removeRule(string $key): bool
    {
        $lease = $this->tryAcquireLease();
        if ($lease === null) {
            return false;
        }

        try {
            if (!$this->rules->exists($key)) {
                return false;
            }
            $now = $this->now();
            if (!$this->settleExistingBucket($key, $now) || !$this->rules->del($key)) {
                return false;
            }
            $this->applyRule($key, $this->defaultRule(), $now);
            return true;
        } finally {
            $this->releaseLease($lease);
        }
    }

    public function access(string $key, ?int $limitQps): bool
    {
        $lease = $this->tryAcquireLease();
        if ($lease === null) {
            return false;
        }

        try {
            $nowNs = hrtime(true);
            $now = $nowNs / 1e9;
            $defaults = $this->defaultRule();
            $autoGc = $this->autoGc->get() !== 0;
            if ($autoGc) {
                $lastGcAt = max($this->gcChangedAt->get(), $this->lastGcAt->get());
                if ($nowNs - $lastGcAt >= $this->gcIntervalNs->get()) {
                    $this->collect($now, $defaults, self::GC_BATCH_SIZE, $key);
                    $this->lastGcAt->set($nowNs);
                }
            }

            $rule = $this->rules->get($key) ?: $defaults;
            $refillRate = $limitQps === null ? $rule['rate'] : min($limitQps, $rule['rate']);
            $bucket = $this->buckets->get($key);
            if ($bucket === false) {
                $bucket = [
                    'tokens' => $limitQps === null ? $rule['capacity'] : min($limitQps, $rule['capacity']),
                    'batchAt' => (int) ($now * 1e9),
                    'rate' => $rule['rate'],
                    'capacity' => $rule['capacity'],
                    'refillRate' => $refillRate,
                    'sampleSecond' => (int) $now,
                    'currentCount' => 0,
                    'previousCount' => 0,
                    'olderCount' => 0,
                ];
                if (!$this->insertBucket($key, $bucket)) {
                    if (!$autoGc) {
                        return false;
                    }
                    $this->collect($now, $defaults, self::GC_BATCH_SIZE, $key);
                    if (!$this->insertBucket($key, $bucket)) {
                        return false;
                    }
                }
            } else {
                $bucket = $this->settle($key, $bucket, $rule, $now, $refillRate, $limitQps);
                if ($bucket === null) {
                    return false;
                }
            }

            $this->recordAttempt($key, $bucket, (int) $now);
            if ($bucket['tokens'] < 0) {
                return false;
            }

            $remaining = $this->buckets->decr($key, 'tokens', 1);
            return $remaining !== false && $remaining >= 0;
        } finally {
            $this->releaseLease($lease);
        }
    }

    public function qps(string $key): float
    {
        $bucket = $this->buckets->get($key);
        if ($bucket === false) {
            return 0.0;
        }

        $age = (int) $this->now() - $bucket['sampleSecond'];
        $samples = array_slice(
            [$bucket['currentCount'], $bucket['previousCount'], $bucket['olderCount']],
            0,
            max(0, 3 - $age)
        );
        $activeSeconds = count(array_filter($samples));
        return $activeSeconds === 0 ? 0.0 : round(array_sum($samples) / $activeSeconds, 2);
    }

    public function gc(?string $key): int
    {
        $keys = $key === null ? $this->candidateKeys(null) : [$key];
        $nowNs = hrtime(true);
        $defaults = $this->defaultRule();
        $candidates = [];
        foreach ($keys as $candidate) {
            if ($this->needsReclaimCheck($candidate, $defaults, $nowNs)) {
                $candidates[] = $candidate;
            }
        }

        $removed = 0;
        $total = count($candidates);
        for ($offset = 0; $offset < $total; $offset += self::GC_BATCH_SIZE) {
            $lease = $this->tryAcquireLease();
            if ($lease === null) {
                break;
            }

            try {
                $now = $this->now();
                $defaults = $this->defaultRule();
                foreach (array_slice($candidates, $offset, self::GC_BATCH_SIZE) as $candidate) {
                    $removed += (int) $this->reclaim($candidate, $defaults, $now);
                }
            } finally {
                $this->releaseLease($lease);
            }
        }
        return $removed;
    }

    private function tryAcquireLease(): ?int
    {
        $deadline = hrtime(true) + self::LEASE_WAIT_NS;
        do {
            $owner = $this->lease->get();
            $nowMs = intdiv(hrtime(true), 1000000);
            if ($owner === 0 || $nowMs - ($owner >> self::LEASE_SEQUENCE_BITS) >= self::LEASE_DURATION_MS) {
                // The timestamp and sequence share one word so a takeover compares both.
                $previous = $this->lastLeaseToken->get();
                $token = max($nowMs << self::LEASE_SEQUENCE_BITS, $previous + 1);
                if (($token >> self::LEASE_SEQUENCE_BITS) === $nowMs
                    && $this->lastLeaseToken->cmpset($previous, $token)
                    && $this->lease->cmpset($owner, $token)
                ) {
                    $age = intdiv(hrtime(true), 1000000) - $nowMs;
                    if ($age < self::LEASE_DURATION_MS) {
                        return $token;
                    }

                    // A worker suspended during acquisition must discard an already expired token.
                    $this->releaseLease($token);
                }
            }

            if (Coroutine::getCid() >= 0) {
                if (Coroutine::sleep(0.001) === false) {
                    return null;
                }
            } else {
                usleep(1000);
            }
        } while (hrtime(true) < $deadline);

        return null;
    }

    private function releaseLease(int $token): void
    {
        $this->lease->cmpset($token, 0);
    }

    private function settleExistingBucket(string $key, float $now): bool
    {
        $bucket = $this->buckets->get($key);
        if ($bucket === false) {
            return true;
        }

        $rule = $this->rules->get($key) ?: $this->defaultRule();
        $rate = min($bucket['refillRate'], $rule['rate']);
        return $this->settle($key, $bucket, $rule, $now, $rate, null) !== null;
    }

    private function applyRule(string $key, array $rule, float $now): void
    {
        $bucket = $this->buckets->get($key);
        if ($bucket !== false) {
            $this->settle($key, $bucket, $rule, $now, $rule['rate'], null);
        }
    }

    private function settle(string $key, array $bucket, array $rule, float $now, float $refillRate, ?int $limitQps): ?array
    {
        $ruleChanged = $bucket['rate'] != $rule['rate'] || $bucket['capacity'] != $rule['capacity'];
        $nowNs = (int) ($now * 1e9);
        $batchAt = $bucket['batchAt'];
        $tokens = $bucket['tokens'];
        if ($ruleChanged) {
            // A rule can have changed while this bucket was idle. Discard unclaimed old batches.
            $batchAt = $nowNs;
        } else {
            $period = $this->refillPeriod($bucket['refillRate']);
            $batches = intdiv(max(0, $nowNs - $batchAt), $period);
            if ($batches >= 1) {
                $tokens = max(0, $tokens);
                $space = $rule['capacity'] - $tokens;
                $tokens += (int) min($batches, $space);
                $batchAt += $batches * $period;
            }
        }
        if ($bucket['refillRate'] != $refillRate) {
            $batchAt = $nowNs;
        }
        $tokens = min($tokens, $rule['capacity'], $limitQps ?? PHP_INT_MAX);

        // Claim B before publishing A. Death between these writes only loses this refill.
        if ($batchAt > $bucket['batchAt'] && !$this->buckets->set($key, ['batchAt' => $batchAt])) {
            return null;
        }
        if ($tokens !== $bucket['tokens'] || $ruleChanged || $bucket['refillRate'] != $refillRate) {
            if (!$this->buckets->set($key, [
                'tokens' => $tokens,
                'refillRate' => $refillRate,
                'rate' => $rule['rate'],
                'capacity' => $rule['capacity'],
            ])) {
                return null;
            }
        }
        $bucket['tokens'] = $tokens;
        return $bucket;
    }

    private function recordAttempt(string $key, array $bucket, int $second): void
    {
        $age = $second - $bucket['sampleSecond'];
        if ($age <= 0) {
            $this->buckets->incr($key, 'currentCount', 1);
            return;
        }

        $this->buckets->set($key, [
            'sampleSecond' => $second,
            'currentCount' => 1,
            'previousCount' => $age === 1 ? $bucket['currentCount'] : 0,
            'olderCount' => $age === 1 ? $bucket['previousCount'] : ($age === 2 ? $bucket['currentCount'] : 0),
        ]);
    }

    private function insertBucket(string $key, array $bucket): bool
    {
        return $this->buckets->count() < $this->maxToken && @$this->buckets->set($key, $bucket);
    }

    private function collect(float $now, array $defaults, int $budget, ?string $activeKey = null): int
    {
        $removed = 0;
        foreach ($this->candidateKeys($budget) as $key) {
            if ($key !== $activeKey && $this->reclaim($key, $defaults, $now)) {
                $removed++;
            }
        }
        return $removed;
    }

    private function candidateKeys(?int $budget): array
    {
        // A Table iterator belongs to this worker and can be shared by its coroutines.
        if ($this->scanInProgress) {
            return [];
        }
        $this->scanInProgress = true;
        try {
            if ($budget === null || !$this->scanStarted || !$this->buckets->valid()) {
                $this->buckets->rewind();
                $this->scanStarted = true;
            }

            $keys = [];
            while ($this->buckets->valid() && ($budget === null || count($keys) < $budget)) {
                $keys[] = $this->buckets->key();
                $this->buckets->next();
            }
            return $keys;
        } finally {
            $this->scanInProgress = false;
        }
    }

    private function needsReclaimCheck(string $key, array $defaults, int $nowNs): bool
    {
        $bucket = $this->buckets->get($key);
        if ($bucket === false) {
            return false;
        }

        $rule = $this->rules->get($key) ?: $defaults;
        if ($bucket['rate'] != $rule['rate'] || $bucket['capacity'] != $rule['capacity']) {
            // Reconcile a changed default under the lease before judging capacity.
            return true;
        }

        $period = $this->refillPeriod($bucket['refillRate']);
        $batches = intdiv(max(0, $nowNs - $bucket['batchAt']), $period);
        return $batches >= $rule['capacity'] - max(0, $bucket['tokens']);
    }

    private function refillPeriod(float $rate): int
    {
        $period = ceil(1e9 / $rate);
        return $period >= PHP_INT_MAX ? PHP_INT_MAX : max(1, (int) $period);
    }

    private function reclaim(string $key, array $defaults, float $now): bool
    {
        $bucket = $this->buckets->get($key);
        if ($bucket === false) {
            return false;
        }
        $rule = $this->rules->get($key) ?: $defaults;
        $bucket = $this->settle($key, $bucket, $rule, $now, min($bucket['refillRate'], $rule['rate']), null);
        return $bucket !== null && $bucket['tokens'] >= $rule['capacity'] && $this->buckets->del($key);
    }

    private function createTable(int $size, array $columns): Table
    {
        $table = new Table($size, 1.0);
        foreach ($columns as $name => $type) {
            $table->column($name, $type, 8);
        }

        if (!$table->create()) {
            throw new \RuntimeException('Unable to allocate limiter shared memory');
        }
        return $table;
    }

    private function defaultRule(): array
    {
        $qps = $this->defaultQps->get();
        return ['rate' => $qps, 'capacity' => $qps];
    }

    private function now(): float
    {
        return function_exists('hrtime') ? hrtime(true) / 1e9 : microtime(true);
    }
}
