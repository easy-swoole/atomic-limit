<?php


namespace EasySwoole\AtomicLimit;


use EasySwoole\Component\Process\AbstractProcess;
use Swoole\Coroutine;
use Swoole\Table;

class Process extends AbstractProcess
{
    protected function run($arg)
    {
        /** @var Table $table */
        $table = $arg['table'];
        while (1){
            $list = [];
            $hit = 0;
            $time = time();
            foreach ($table as $key => $value){
                if($time - $value['accessTime'] > 3){
                    $hit++;
                    $list[] = $key;
                }
                //内存保护
                if($hit > 1024*64){
                    break;
                }
            }
            if(!empty($list)){
                foreach ($list as $key){
                    $table->del($key);
                }
            }else{
                Coroutine::sleep($arg['gcInterval']);
            }
        }
    }
}