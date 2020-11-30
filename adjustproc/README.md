# 自动调整进程例子


### 创建测试数据

参考以下地址创建测试数据
https://www.cnblogs.com/bjx2020/p/9727898.html

1.创建基础表
```
CREATE TABLE `Db_Example` (
 `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
 `userId` varchar(36) NOT NULL DEFAULT '' COMMENT '用户id',
 `status` tinyint(2) unsigned NOT NULL DEFAULT '1' COMMENT '状态:1-正常,2-已删除',
 `groupId` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '用户组id:0-未激活用户,1-普通用户,2-vip用户,3-管理员用户',
 `vnum` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '投票数',
 `token` varchar(20) NOT NULL DEFAULT '' COMMENT 'TOKEN',
 `createTime` datetime NOT NULL DEFAULT '1971-01-01 01:01:01',
 PRIMARY KEY (`id`),
 KEY `idx_userId` (`userId`) USING HASH COMMENT '用户ID哈希索引'
) ENGINE=INNODB DEFAULT CHARSET=utf8;
```

2.创建内存表
利用 MySQL 内存表插入速度快的特点
```
CREATE TABLE `Db_Example_memory` (
 `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
 `userId` varchar(36) NOT NULL DEFAULT '' COMMENT '用户id',
 `status` tinyint(2) unsigned NOT NULL DEFAULT '1' COMMENT '状态:1-正常,2-已删除',
 `groupId` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '用户组id:0-未激活用户,1-普通用户,2-vip用户,3-管理员用户',
 `vnum` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '投票数',
 `token` varchar(20) NOT NULL DEFAULT '' COMMENT 'TOKEN',
 `createTime` datetime NOT NULL DEFAULT '1971-01-01 01:01:01',
 PRIMARY KEY (`id`),
 KEY `idx_userId` (`userId`) USING HASH COMMENT '用户ID哈希索引'
) ENGINE=MEMORY DEFAULT CHARSET=utf8;
```

3.创建存储过程
```
DELIMITER $$
SET NAMES utf8 $$

DROP FUNCTION IF EXISTS `rand_strings` $$
CREATE FUNCTION `rand_strings`(n INT) RETURNS varchar(255) CHARSET utf8
BEGIN
	DECLARE char_str varchar(100) DEFAULT 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    DECLARE return_str varchar(255) DEFAULT '';
    DECLARE i INT DEFAULT 0;
    WHILE i < n DO
        SET return_str = concat(return_str, substring(char_str, FLOOR(1 + RAND()*62), 1));
        SET i = i+1;
    END WHILE;
    RETURN return_str;
END $$

DROP FUNCTION IF EXISTS `rand_datetime` $$
CREATE FUNCTION `rand_datetime`(sd DATETIME,ed DATETIME) RETURNS DATETIME
BEGIN
    RETURN DATE_ADD(sd,INTERVAL FLOOR(1+RAND()*((ABS(UNIX_TIMESTAMP(ed)-UNIX_TIMESTAMP(sd)))-1)) SECOND);
END$$

DROP PROCEDURE IF EXISTS `insert_db_example_memory` $$
CREATE PROCEDURE `insert_db_example_memory`(IN n INT)
BEGIN
	DECLARE i INT DEFAULT 1;
    DECLARE vnum INT DEFAULT 0;
    DECLARE groupId INT DEFAULT 0;
    DECLARE status TINYINT DEFAULT 1;
    WHILE i <= n DO
        SET vnum = FLOOR(1 + RAND() * 10000);
        SET groupId = FLOOR(0 + RAND()*3);
        SET status = FLOOR(1 + RAND()*2);
        INSERT INTO `Db_Example_memory` VALUES (NULL, uuid(), status, groupId, vnum, rand_strings(20), rand_datetime(DATE_FORMAT('2000-01-01 00:00:00','%Y-%m-%d %H:%i:%s'), DATE_FORMAT('2020-10-31 23:59:59','%Y-%m-%d %H:%i:%s')));
        SET i = i + 1;
    END WHILE;
END $$

DELIMITER ;
```

4.调用存储过程,创建10w数据

出现内存已满时，修改 max_heap_table_size 参数的大小
```
CALL insert_db_example_memory(10000);
```

5.从内存表插入基础表
```
INSERT INTO `Db_Example` SELECT * FROM `Db_Example_memory`;
```

### 部署进程

1.引入配置

在配置中可获取reids,和db实例， 详情查看config.php

redis使用例子
```
try{
    $redis = getRedis('127.0.0.1');
    if(! $redis) throw new \Exception('redis disconnect');
    echo $redis->me()->ping();
    // 队列
    $redis->qPut('QUEUE_EXAMPLE_1', array('id'=>1));
    $datas = $redis->get('QUEUE_EXAMPLE_1');
} catch(\Exception $ex) {
}
```

db使用例子
```
try {
    $db = getDb('127.0.0.1', 'root', 'root', 'test');
    if(! $db) throw new \Exception('db disconnection.');

    $rt = $db->query('select * from `example`');
    print_r($rt);
    echo $db->sql();
} catch(\Exception $ex) {
}
```

2.动态调整进程原理解释

第一个进程启动前，安装2个定时器

定时器1) 查询要处理的数据放入队列
定时器2) 根据每次查询到的数据长度，调整进程数


定时器1处理流程
查询要处理的数据放入队列
```
队列长度为0 -是-> 当前进程加锁 -锁成功-> 1 查询N条数据          ->  循环数据完成 -锁相同-> 删除锁
    |            |                   2 并循环数据处理                                              
    |            |                   3 获取锁名和自已锁检查 -锁相同-> 1 数据放入队列                    
    |            |                                          |      2 更新数据状态，更新数据超时时间 
    |            |                                          |
    否           失败                                        否
    |->          |->                                        |->
```
检查超时未处理的数据重新放回队列
```
查询超时的数据，更新数据状态，保证下次可再放回队列处理

# 代码查看AdjustProTask.php
```

定时器2处理流程
```
# public function onWorkerStart() 当中的逻辑

检查数据长度满员次数>0(查询N条数据， N条数据和查询出的值一样)
当前启动不是最大进程数
        |
        |-满以上条件 -> 通知主进程调整 当前任务进程数到最大
        |
        否
        |
        v
检查数据长度满员次数=0
当前启动是最大进程数
        |
        |-满以上条件 -> 通知主进程调整 当前任务进程数到最小
        |
        否
        |
        v
        不调整进程

# 代码实现

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
```

3.数据处理 

从队列取出数据， 
```
# public function run() 当中的逻辑

检查状态是否正常       - 正常未超时 -> 走处理数据流程
检查是否超时           |
                     v
                     跳过不处理，等待定时器2收回数据


# 代码实现

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
```




