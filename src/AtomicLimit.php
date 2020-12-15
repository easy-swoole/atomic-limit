<?php


namespace EasySwoole\AtomicLimit;

use EasySwoole\Component\Process\Config;
use Swoole\Server;
use Swoole\Table;

class AtomicLimit
{
    private $accessTable;
    private $limitQps = 10;
    private $gcInterval = 3;
    private $hashAttach = false;

    public function __construct(int $maxToken = 1024*256)
    {
        //采样为近期3个秒
        $this->accessTable = new Table($maxToken*3);
        $this->accessTable->column('times',Table::TYPE_INT,4);
        $this->accessTable->column('accessTime',Table::TYPE_INT,4);
        $this->accessTable->create();
    }

    /**
     * @param int $limitQps
     */
    public function setLimitQps(int $limitQps): void
    {
        $this->limitQps = $limitQps;
    }

    function access(string $token,?int $limitQps = null):?bool
    {
        if(!$this->hashAttach){
            return null;
        }
        if($limitQps === null){
            $limitQps = $this->limitQps;
        }
        $timePoint =  $n = time()%10;
        $key = $this->hashKey($token,$timePoint);
        $current = $this->accessTable->incr($key,'times',1);
        $this->accessTable->set($key,['accessTime'=>time()]);
        if($current > $limitQps){
            return false;
        }
        if($current > $this->qps($token)){
            return false;
        }
        return  true;
    }

    function qps(string $token):?float
    {
        if(!$this->hashAttach){
            return null;
        }
        $points = $this->getTimeBack2Point();
        $count = 0;
        $allTimes = 0;
        foreach ($points as $point){
            $key = $this->hashKey($token,$point);
            $info = $this->accessTable->get($key);
            if($info){
                $count++;
                $allTimes += $info['times'];
            }
        }
        return round($allTimes/$count,2);
    }

    function attachServer(Server $server,string $serverName = 'EasysSwoole')
    {
        $con = new Config();
        $con->setArg([
            'table'=>$this->accessTable,
            'gcInterval'=>$this->gcInterval
        ]);
        $con->setProcessGroup("{$serverName}.AtomicLimit");
        $con->setProcessName("{$serverName}.AtomicLimit.Worker");
        $con->setEnableCoroutine(true);
        $p = new Process($con);
        $server->addProcess($p->getProcess());
        $this->hashAttach = true;
    }

    /**
     * @param float $gcInterval
     */
    public function setGcInterval(float $gcInterval): void
    {
        $this->gcInterval = $gcInterval;
    }

    private function getTimeBack2Point():array
    {
        $n = time()%10;
        return [
            $n,abs($n-1),abs($n - 2)
        ];
    }

    private function hashKey(string $token,?int $timePoint = null):string
    {
        if($timePoint === null){
            $timePoint = time()%10;
        }
        return substr(md5($token),8,16)."_{$timePoint}";
    }

}