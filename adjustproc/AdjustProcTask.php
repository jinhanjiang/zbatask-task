<?php
namespace Task;

use Zba\Process;
use Zba\Task;
use Zba\Timer;

// 当前配置可加到config.php中
// Redis
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PASS', '');
// MySQL数据库
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_NAME', 'test');

/**
 * 
 */

class AdjustProcTask extends Task
{
	public function __construct() {
		$this->count = 1; // 启动最小进程数
		$this->maxCount = 50; // 启动最大进程数
		$this->fullProcessCount = 0; // 持续查询到limit条数据次数
		$this->isMaxProcess = false; // 是否调整为最大进程
		// 其他配置
		$this->nextSleepTime = 0;
		$this->queueName = 'QUEUE_EXAMPLE_1';
		$this->closure = $this->run();
		parent::__construct();
	}

	public function onWorkerStart() 
	{
		return function(Process $worker) 
		{
			// 在第一个进程中启动定时器
			if(1 == $worker->id) {
				Timer::add(5, function() use($worker){
					try{
						// 任务队列长度为0， 则队列中无数据，需要补数据
						$redis = getRedis(REDIS_HOST, REDIS_PASS);
						$db = getDb(DB_HOST, DB_USER, DB_PASS, DB_NAME); 
						$qlen = $redis->qLen($this->queueName);
						if(0 == $qlen) 
						{
							$lockVal = md5($worker->pid.'|'.getMacAddr()); $lockName = $this->queueName.'_LOCK';
							$flag = $redis->setnx($lockName, $lockVal, 5); // 15秒后锁自动释放
							if($flag) // 加锁成功
							{
								$limit = 500;
								$sql = <<<SQL
								SELECT 
									`id`
								FROM 
									`Db_Example` 
								WHERE
									`id`>0
									AND `status`='1'
								ORDER BY
								 	`id` ASC 
								LIMIT {$limit}
SQL;
// 上面SQL;这个必需在第一列，否则报错，且当前备注不能写在SQL;后面
								$objs = $db->query($sql);
								if(($ct = count($objs)) > 0)
									foreach($objs as $obj) {
									// 查询到数据，可以做些其他处理，满足条件的放入队列
									$qdata = array(
										'id'=>$obj->id,
									);
									// （多台机器同时执行）锁相同，才执行下面的操作
		                            if($lockVal == $redis->get($lockName)) {
		                            	$retryTimes = 0;
		                            	while(true) {
		                            		// 向队列中放数据
											if($redis->qPut($this->queueName, $qdata)) 
											{
												$now = date('Y-m-d H:i:s', strtotime('+15 sec'));
												$db->query("UPDATE `Db_Example` SET `status`=2,`createTime`='{$now}' WHERE `id`={$obj->id}");
												break;
											}
											// 防止死循环
											if($retryTimes++ >= 3) break;
										}
									}
								}
								// 执行完任务删除锁
								if($lockVal == $redis->get($lockName)) {
									$redis->del($lockName);
								}

								// 设置是否增加进程数到最大
								if($limit == $ct) { // 持续查询到limit条数据, 则启动最大进程数处理
									if($this->fullProcessCount < 5) $this->fullProcessCount ++;
								}
								else {
									if($this->fullProcessCount > 0) $this->fullProcessCount --;
								}
							}
						}

						// 将超进未处理的数据改回状态
						$now = date('Y-m-d H:i:s');
						$db->query("UPDATE `Db_Example` SET `status`=1 WHERE `createTime`<='{$now}'");

					} catch(\Exception $ex) {
					}
				});

				// 定时调整进程数
				Timer::add(15, function() use($worker) {
					if($this->fullProcessCount > 0 && ! $this->isMaxProcess) {
						// 最大进程数, 且当前不是最大进程状态
						$worker->adjustProcessCount($this->maxCount);
						$this->isMaxProcess = true;
					}
					else if($this->fullProcessCount <= 0 && $this->isMaxProcess) {
						// 最小进程数, 且当前不是最小进程状态
						$worker->adjustProcessCount($this->count);
						$this->isMaxProcess = false;
					}
				});

			}
		};
	}

	public function onWorkerStop() 
	{
		return function(Process $worker) 
		{

		};
	}

	public function run()
	{
		return function(Process $worker) 
		{
			$nowTime = time(); $delayTime = strtotime('+1 sec');
			if($this->nextSleepTime == 0) $this->nextSleepTime = $delayTime;
			if($this->nextSleepTime < $nowTime)
			{
				try{
					$redis = getRedis(REDIS_HOST, REDIS_PASS);
					$qdata = $redis->qGet($this->queueName);
					if($qdata && $qdata['id'])
					{
						$db = getDb(DB_HOST, DB_USER, DB_PASS, DB_NAME); 

						$objs = $db->query("SELECT * from `Db_Example` WHERE `id`={$qdata['id']}");
						if(isset($objs[0]) && $objs[0]->createTime > date('Y-m-d H:i:s')) 
						{
							// 处理业务逻辑
							$db->query("DELETE FROM `Db_Example` WHERE `id`={$qdata['id']}");							
						}

					}
					else {
						$this->nextSleepTime = strtotime('+5 sec');		
					}
				} catch(\Exception $ex) {
				}
				
			}
		};
	}
}