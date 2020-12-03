<?php
/**
 * 配置文件，放在和zba文件同级目录下
 */
ini_set('default_socket_timeout', -1);  //不超时


/**
 * 获取Redis查询（需要PHP 7版本，以下用到匿名类）
 * https://github.com/phpredis/phpredis/
 	try{
	 	$redis = getRedis('127.0.0.1');
		if(! $redis) throw new \Exception('redis disconnect');
		echo $redis->me()->ping();
		// 队列
		$redis->qPut('QUEUE_EXAMPLE_1', array('id'=>1));
		$datas = $redis->get('QUEUE_EXAMPLE_1');
	} catch(\Exception $ex) {
	}
 */
function getRedis($redisHost, $redisPass='', $redisPort=6379) 
{
	$retryTimes = 0; $key = md5($redisHost.$redisPass.$redisPort);
	while(true) {
		try{
			if(! isset($GLOBALS["QUEUE_REDIS_{$key}"]) || ! $GLOBALS["QUEUE_REDIS_{$key}"]) 
			{
				$redisfunc = function() use($redisHost, $redisPass, $redisPort) {
					return new class($redisHost, $redisPass, $redisPort) {
						private static $redis = null;
						function __construct($redisHost, $redisPass='', $redisPort=6379) {
							self::$redis = new \Redis();
							self::$redis->connect($redisHost, $redisPort);
							if($redisPass) self::$redis->auth($redisPass);
							self::$redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
						}
						public static function me() {
							return self::$redis;
						}
						public static function get($key) {
							return self::me()->get($key);
						}
						public static function set($key, $val, $exp=0) {
							$isOk = self::me()->set($key, $val);
					        if($isOk && ! empty($exp)) self::me()->expire($key, $exp);
					        return $isOk;
						}
						public static function setnx($key, $val, $exp=0) {
							$isOk = self::me()->setnx($key, $val);
					        if($isOk && ! empty($exp)) self::me()->expire($key, $exp);
					        return $isOk;
						}
						public static function del($key) {
							return self::me()->del($key);
						}
						public static function qPut($qname, $data) {
							if(! $qname) throw new \Exception('Queue name can not be empty');
					        $nowTime = array('TimePutInQueue'=>date('Y-m-d H:i:s'));
					        if(is_array($data)) $data = json_encode($data + $nowTime);
					        else if(is_object($data)) $data = json_encode((array)$data + $nowTime);
					        else if(is_scalar($data)) $data = json_encode(array("name"=>strval($data)) + $nowTime, 256);
					        else return false;
					        return self::me()->lPush($qname, $data);
						}
						public static function qGet($qname) {
							if(! $qname) throw new \Exception('Queue name can not be empty');
							$data = self::me()->rPop($qname);
					        if($data) $data = json_decode($data, true);
					        return $data;
						}
						public static function qLen($qname) {
							if(! $qname) throw new \Exception('Queue name can not be empty');
							return self::me()->lLen($qname);
						}
					};
				};
				$redis = $redisfunc();
				$GLOBALS["QUEUE_REDIS_{$key}"] = $redis;
			} else {
				$redis = $GLOBALS["QUEUE_REDIS_{$key}"];
			}
			$pong = $redis->me()->ping();
			if(strtoupper('+PONG') != $pong) {
				throw new \Exception('Need reconnect');	
			}
			break;
		} catch(\Exception $ex) {
			if($retryTimes++ > 10) break; usleep(10000);
            $GLOBALS["QUEUE_REDIS_{$key}"] = NULL;
		}
	}
	return $GLOBALS["QUEUE_REDIS_{$key}"];
}

/**
 * 数据MySQL数据库查询（需要PHP 7版本，以下用到匿名类）
 * 
 * 以下为简单用法
 	try {
	 	$db = getDb('127.0.0.1', 'root', 'root', 'test');
	 	if(! $db) throw new \Exception('db disconnection.');

	 	$rt = $db->query('select * from `example`');
	 	print_r($rt);
	 	echo $db->sql();
 	} catch(\Exception $ex) {
 	}
 */
function getDb($dbHost, $dbUser, $dbPass, $dbName, $port=3306) 
{
	$retryTimes = 0; $key = md5($dbHost.$dbUser.$dbPass.$dbName.$port);
	while(true) {
        try{
            if(! isset($GLOBALS["QUEUE_DB_{$key}"]) || ! $GLOBALS["QUEUE_DB_{$key}"])  
            {
            	$dbfunc = function() use($dbHost, $dbUser, $dbPass, $dbName, $port) {
            		return new class($dbHost, $dbUser, $dbPass, $dbName, $port) {
            			private static $mysqli = null;
            			private static $lastSQL = '';
            			private static $retry = 0;
            			function __construct($dbHost, $dbUser, $dbPass, $dbName, $port) {
            				self::$mysqli = new \mysqli($dbHost, $dbUser, $dbPass, $dbName, $port);
            			}
            			public static function query($sql) {
            				$result = NULL;
            				$sql = preg_replace(array("/\n/", "/\s+/"), " ", trim($sql));
            				self::$lastSQL = $sql;
            				try{
            					$stmt = self::$mysqli->query($sql);
	            				if(false !== $stmt) {
									if(preg_match('/^INSERT/i', $sql)) $result = self::$mysqli->insert_id;
									else if(preg_match('/^(UPDATE|DELETE)/i', $sql)) $result = $stmt->num_rows;
									else if(preg_match('/^(SELECT|CALL|EXPLAIN|SHOW|PRAGMA)/i', $sql)) {
										$result = (array)$result;
										while($obj = $stmt->fetch_object()) {
											$result[] = $obj;
										}
									}
									//$stmt->close(); //please do not set this call, it's will affect result return
								}
								self::$retry = 0;
							} catch(Exception $ex) {
								if(self::$retry < 10 
									&& preg_match('/MySQL server has gone away/i', $ex->getMessage())) {
									self::$retry ++; usleep(10000);
                					return self::$query($sql);
                				}
							}
							return $result;
            			}
            			public static function sql() {
            				return self::$lastSQL;
            			}
            		};
            	};
            	$db = $dbfunc();
                $GLOBALS["QUEUE_DB_{$key}"] = $db;
            }
            else{
                $db = $GLOBALS["QUEUE_DB_{$key}"];
            }
            $rt = $db->query('SELECT 1 AS `id`');
            if(isset($rt[0]) && $rt[0]->id) break;
            else {
                throw new \Exception('Need reconnect');
            }
        } catch(\Exception $ex) {
            if($retryTimes++ > 10) break; usleep(10000);
            $GLOBALS["QUEUE_DB_{$key}"] = NULL;
        }
    }
    return $GLOBALS["QUEUE_DB_{$key}"];
}

/**
 * 获取当前服务器的mac地址(分布式做唯一键判读)
 */
function getMacAddr() {
    $result = array();
    if(preg_match('/^win/i', PHP_OS)) {
        @exec("ipconfig /all", $result);
        if (! $result) {
            $ipconfig = $_SERVER["WINDIR"] . "\system32\ipconfig.exe";
            if (is_file($ipconfig)) {
                @exec($ipconfig . " /all", $result);
            } else {
                @exec($_SERVER["WINDIR"] . "\system\ipconfig.exe /all", $result);
            }
        }
    } else {
        @exec("ifconfig -a", $result);
    }
    $macAddr = '00:00:00:00:00:00';
    foreach ($result as $val) {
        preg_match('/[0-9a-f]{2}([:-][0-9a-f]{2}){5}/i', $val, $out);
        if(isset($out[0])) {
            $macAddr = $out[0];//多个网卡时，会返回第一个网卡的mac地址，一般够用。
            break;
        }
    }
    return $macAddr;
}