
# 实现Websocket服务

### 一 安装服务到zbatask下

将当前目录下的php文件拷贝到zbatask/task目录下

### 二 调整启动服务端口

1 服务默认启动1223端口，如果要调整端口可以在zbatask/.env中添加如下， 

```
[WebsocketEventTask]
port = 9501
```

2 修改zbatask/task/WebsocketEventTask.php中端口
```
public function onWorkerStart() 
{
  return function(Process $worker) 
  {
    if(1 == $worker->id) {
      $port = isset($this->envConfig['port']) ? $this->envConfig['port'] : 1223;
      $this->ws = new WebsocketServer("0.0.0.0:{$port}");
    }
  };
}
```

### 三 处理接收的消息

处理前端发送的消息，编辑WebsocketEvent.php

```
<?php
namespace Task;

class WebsocketEvent
{
    /**
     * 当收到握手信息后回调
     */
    public static function onHandshake($ws, $id, $header) {
        return $header;
    }

    /**
     * 当握手成功后回调
     */
    public static function onConnection($ws, $id) {}

    /**
     * 当链接关闭时回调
     */
    public static function onClose($ws, $id) {}

    /**
     * 当有消息时回调
     */
    public static function onMessage($ws, $id, $message)
    {
        $onlinecnt = count($ws->connections);
        $ws->send($id, "myid: {$id}, online: {$onlinecnt}, message: {$message}");

        // 发送给其他人
        foreach($ws->connections as $cid=>$conn) {
            if($cid != $id) $ws->send($cid, "id: {$id}, online: {$onlinecnt}, message: {$message}");
        }
    }
}
```

### 四 启动服务
```
./zba start
```

### 五 前端发消息测试

创建前端页面注意端口和启动的服务端口保持一致

```
<html>
<head>
  <meta charset="utf-8">
  <title>Web sockets test</title>
  <script type="text/javascript">
    var ws;
    function connectWs() {
      ws = new WebSocket("ws://127.0.0.1:1223");
      ws.onopen = function(event){
        console.log("connect succ");
      };
      ws.onmessage = function(event){
        console.log("recvFromSever：\r\n"+event.data);
      };
      ws.onclose = function(event){
        console.log("onclose exec");
        setTimeout(function(){ connectWs(); }, 15000);
      };
      ws.onerror = function(event){
        console.log("onerror exec");
      };
    }
    connectWs();
    function send(){
      ws.send(Math.random() + "\r\n");
    }
  </script>
</head>
<body>
  <a href="javascript:;" onclick="send();">Send</a>
</body>
</html>
```
