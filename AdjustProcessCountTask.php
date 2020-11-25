<?php
/**
 * This file is part of zba.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    jinhanjiang<jinhanjiang@foxmail.com>
 * @copyright jinhanjiang<jinhanjiang@foxmail.com>
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Task;

use Zba\ProcessPool;
use Zba\Process;
use Zba\Task;

class AdjustProcessCountTask extends Task
{
    public function __construct() {
        // 设置任务启动一个进程
        $this->count = 1;

        // 当程序执行reload的时候当前任务不执行
        $this->reload = false;

        // 任务名称
        $this->name = 'AdjustProcessCountTask'; 

        // 做任务的回调方法
        $this->closure = $this->run();

        // 上次任务执行时间
        $this->nextSleepTime = 0;

        parent::__construct();
    }

    public function run()
    {
        return function(Process $worker) 
        {
            // 每5分钟执行下面的任务，请不要在程序中使用(sleep, exit, die)
            $nowTime = time(); $delayTime = strtotime('+5 minute');
            if($this->nextSleepTime == 0) $this->nextSleepTime = $delayTime;
            if($this->nextSleepTime < $nowTime)
            {
                // 假设根据 队列 长度来调整执行 进程数
                // 例如: 队列长度大于100 启动2个进程， 小于100启动1个进程
                $nowCount = 2;

                $worker->pipeWrite(json_encode(
                    array(
                        'action'=>'setProcessCount',
                        'taskName'=>'DefaultTask',
                        'count'=>$nowCount,
                    )
                ), $worker->masterProcessPipeFile);

                $this->nextSleepTime = strtotime('+5 minute');
            }
        };
    }
}