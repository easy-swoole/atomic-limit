<?php

namespace EasySwoole\AtomicLimit;

use Swoole\Atomic\Long;

class AtomicLimit
{
    private $defaultQps;
    private $autoGc;
    private $gcIntervalNs;
    private $gcChangedAt;
    private $shards = [];

    public function __construct(int $maxToken = 1024 * 256, int $lockerNum = 3)
    {
        if ($maxToken < 1) {
            throw new \InvalidArgumentException('maxToken must be positive');
        }
        if ($lockerNum < 1) {
            throw new \InvalidArgumentException('lockerNum must be positive');
        }

        $this->defaultQps = new Long(10);
        $this->autoGc = new Long(1);
        $this->gcIntervalNs = new Long(3000000000);
        $this->gcChangedAt = new Long(0);
        $shardCount = min($lockerNum, $maxToken);
        $capacity = intdiv($maxToken, $shardCount);
        $remainder = $maxToken % $shardCount;
        for ($index = 0; $index < $shardCount; $index++) {
            $this->shards[] = new AtomicLimitShard(
                $capacity + (int) ($index < $remainder),
                $this->defaultQps,
                $this->autoGc,
                $this->gcIntervalNs,
                $this->gcChangedAt
            );
        }
    }

    public function setRule(string $token, float $rate, int $capacity): bool
    {
        if (!is_finite($rate) || $rate <= 0 || $capacity < 1) {
            throw new \InvalidArgumentException('rate and capacity must be positive');
        }

        $key = $this->hashKey($token);
        return $this->shardFor($key)->setRule($key, $rate, $capacity);
    }

    public function removeRule(string $token): bool
    {
        $key = $this->hashKey($token);
        return $this->shardFor($key)->removeRule($key);
    }

    public function setLimitQps(int $limitQps): void
    {
        if ($limitQps < 1) {
            throw new \InvalidArgumentException('limitQps must be positive');
        }

        $this->defaultQps->set($limitQps);
    }

    public function access(string $token, ?int $limitQps = null): bool
    {
        if ($limitQps !== null && $limitQps < 1) {
            return false;
        }

        $key = $this->hashKey($token);
        return $this->shardFor($key)->access($key, $limitQps);
    }

    public function qps(string $token): float
    {
        $key = $this->hashKey($token);
        return $this->shardFor($key)->qps($key);
    }

    public function gc(?string $key = null): int
    {
        if ($key !== null) {
            $key = $this->hashKey($key);
            return $this->shardFor($key)->gc($key);
        }

        $removed = 0;
        foreach ($this->shards as $shard) {
            $removed += $shard->gc(null);
        }
        return $removed;
    }

    public function setAutoGc(bool $enabled): void
    {
        $this->autoGc->set((int) $enabled);
    }

    public function setGcInterval(float $gcInterval): void
    {
        if (!is_finite($gcInterval) || $gcInterval <= 0) {
            throw new \InvalidArgumentException('gcInterval must be positive');
        }

        $intervalNs = ceil($gcInterval * 1e9);
        $this->gcChangedAt->set(hrtime(true));
        $this->gcIntervalNs->set($intervalNs >= PHP_INT_MAX ? PHP_INT_MAX : (int) $intervalNs);
    }

    private function hashKey(string $token): string
    {
        return substr(hash('sha256', $token), 0, 48);
    }

    private function shardFor(string $key): AtomicLimitShard
    {
        $index = hexdec(substr($key, 0, 8)) % count($this->shards);
        return $this->shards[$index];
    }
}
