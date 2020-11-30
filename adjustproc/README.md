# 自动调整进程例子


### 创建测试数据

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
        INSERT INTO `Db_Example_memory` VALUES (NULL, uuid(), status, groupId, vnum, rand_strings(20), rand_datetime(DATE_FORMAT('2000-01-01 00:00:00','%Y-%m-%d %H:%i:%s'), DATE_FORMAT('2030-12-31 23:59:59','%Y-%m-%d %H:%i:%s')));
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













