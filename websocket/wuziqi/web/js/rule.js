var bout = false;//是否允许落子
var color = "";//自己落子颜色
var websocket = null;
var row = 15;
var col = 15;
var widthAndHeight = 30;//格子宽度高度

var WuZiQi = {
    isEnd: function(xy, chessmancolor) //判断是否结束游戏
    {
        var id = parseInt(xy);

        //竖的计算
        var num = 1;
        num = WuZiQi.shujia(num, id, chessmancolor);
        num = WuZiQi.shujian(num, id, chessmancolor);
        if(num >= 5)
        {
            var iswin = 0;
            if(chessmancolor == color){
                confirm("游戏结束！你赢了！");
                iswin = 1;
            } else {
                confirm("游戏结束！你输了！");
            }
            WuZiQi.finishGame(iswin);
            return;
        }

        // 横的计算
        num = 1;
        num = WuZiQi.hengjia(num, id, chessmancolor);
        num = WuZiQi.hengjian(num, id, chessmancolor);
        if(num >= 5) 
        {
            var iswin = 0;
            if(chessmancolor == color){
                confirm("游戏结束！你赢了！");
                iswin = 1;
            } else {
                confirm("游戏结束！你输了！");
            }
            WuZiQi.finishGame(iswin);
            return;
        }

        num = 1;
        num = WuZiQi.zuoxiejia(num,id,chessmancolor);
        num = WuZiQi.zuoxiejian(num,id,chessmancolor);
        if(num >= 5)
        {
            var iswin = 0;
            if(chessmancolor == color){
                confirm("游戏结束！你赢了！");
                iswin = 1;
            } else {
                confirm("游戏结束！你输了！");
            }
            WuZiQi.finishGame(iswin);
            return ;
        }

        num = 1;
        num = WuZiQi.youxiejia(num,id,chessmancolor);
        num = WuZiQi.youxiejian(num,id,chessmancolor);
        if(num >= 5)
        {
            var iswin = 0;
            if(chessmancolor == color){
                confirm("游戏结束！你赢了！");
                iswin = 1;
            } else {
                confirm("游戏结束！你输了！");
            }
            WuZiQi.finishGame(iswin);
            return ;
        }
    },
    youxiejia:function(num, id, color)
    {
        var yu = id % row;
        id = id + (row - 1);
    
        if(id < (row * col) && (id % row) < yu)
        {
            var flag = WuZiQi.checkColor(id, color);
            if(flag) {
                num++;
                return WuZiQi.youxiejia(num,id,color);
            } else {
                return num;
            }
        } else {
            return num;
        }
    },
    youxiejian:function(num, id, color)
    {
        var yu = id % row;
        id = id - (row - 1);
        if(id >= 0 && (id % row) > yu) {
            var flag = WuZiQi.checkColor(id,color);
            if(flag) {
                num++;
                return WuZiQi.youxiejian(num,id,color);
            } else {
                return num;
            }
        } else {
            return num;
        }
    },
    zuoxiejia:function(num, id, color)
    {
        var yu = id % row;
        id = id + (row + 1);
        if(id < (row * col) && (id % row) > yu) {
            var flag = WuZiQi.checkColor(id,color);
            if(flag) {
                num++;
                return WuZiQi.zuoxiejia(num,id,color);
            } else {
                return num;
            }
        } else {
            return num;
        }
    },
    zuoxiejian:function(num,id,color)
    {
        var yu = id % row;
        id = id - (row + 1);
        if(id >= 0 && (id % row) < yu) {
            var flag = WuZiQi.checkColor(id,color);
            if(flag) {
                num++;
                return WuZiQi.zuoxiejian(num,id,color);
            } else {
                return num;
            }
        } else {
            return num;
        }
    },
    hengjia:function(num,id,color)
    {
        var yu = id%row;
        id = id+1;
        if(id < (row * col) && (id % row) > yu) {
            var flag = WuZiQi.checkColor(id,color);
            if(flag) {
                num++;
                return WuZiQi.hengjia(num,id,color);
            } else {
                return num;
            }
        } else {
            return num;
        }
    },
    hengjian:function(num,id,color)
    {
        var yu = id % row;
        id = id - 1;
        if(id >= 0 & (id % row) < yu) {
            var flag = WuZiQi.checkColor(id,color);
            if(flag) {
                num++;
                return WuZiQi.hengjian(num,id,color);
            } else {
                return num;
            }
        } else {
            return num;
        }
    },
    shujia:function(num,id,color) 
    {
        id = id + row;
        if(id < (row * col)) {
            var flag = WuZiQi.checkColor(id,color);
            if(flag) {
                num++;
                return WuZiQi.shujia(num,id,color);
            } else {
                return num;
            }
        } else {
            return num;
        }
    },
    shujian:function(num,id,color)
    {
        id = id - row;
        if(id >= 0) {
            var flag = WuZiQi.checkColor(id,color);
            if(flag) {
                num++;
                return WuZiQi.shujian(num,id,color);
            } else {
                return num;
            }
        } else {
            return num;
        }
    },
    checkColor:function(xy,color) {
        if($("#"+xy).children("div").hasClass(color)){
            return true;
        } else {
            return false;
        }
    },
    playchess:function(e)
    {
        if(bout && color != "")
        {
            if($(e).children("div").length > 0) {
                alert("这里已经有子了！请在其它地方落子！");
                return;
            }
            var result = {};
            result.xy = $(e).attr("id");
            result.color = color;

            WuZiQi.sendWsMessage(result);
        }
        else
        {
            if(color == "")
            {
                $("#messagebox").append("系统：游戏还没有开始!" + "\n");
                $("#messagebox").scrollTop($("#messagebox")[0].scrollHeight - $("#messagebox").height());
            }
            else
            {
                $("#messagebox").append("系统：请等待你的对手落子!" + "\n");
                $("#messagebox").scrollTop($("#messagebox")[0].scrollHeight - $("#messagebox").height());
            }
        }
    
    },
    // 游戏结束
    finishGame: function(iswin)
    {
        var result = {};
        result.iswin = iswin;
        WuZiQi.sendWsMessage(result);
    },
    //发送消息
    sendMessage:function()
    {
        var message = $("#message").val();
        if(message != "")
        {
            var result = {};
            result.message = message;
            WuZiQi.sendWsMessage(result);
            $("#message").val("");
        } 
        else 
        {
            $("#messagebox").append("系统：请不要发送空信息!" + "\n");
            $("#messagebox").scrollTop($("#messagebox")[0].scrollHeight - $("#messagebox").height());
        }
    },
    sendWsMessage:function(msg)
    {
        if(websocket != null){
            websocket.send(JSON.stringify(msg) + "\r\n");
        } else {
            $("#messagebox").append("系统：已断开连接" + "\n");
        }
    }
};


$(function(){
    //根据棋盘格子数得到棋盘大小
    $("#background").css({width:(row*widthAndHeight)+"px",height:(col*widthAndHeight)+"px"});
  
    //用canvas画棋盘
    var canvas = document.createElement("canvas");
    $(canvas).attr({width:(row*widthAndHeight)+"px", height:col*widthAndHeight+"px"});
    $(canvas).css({position:"relative", "z-index":9999});
    var cot = canvas.getContext("2d");
    cot.fillStyle = "#EAC000";
    cot.fillRect(0, 0, row*widthAndHeight, col*widthAndHeight);
    cot.lineWidth = 1;
    var offset = widthAndHeight / 2;
    for(var i=0;i<row;i++){//面板大小和棋盘一致，但格子线条比棋盘的行列少1
        cot.moveTo((widthAndHeight*i)+offset,0+offset);
        cot.lineTo((widthAndHeight*i)+offset,(col*widthAndHeight)-offset);
    }
    for(var j=0;j<col;j++){
        cot.moveTo(0+offset,(widthAndHeight*j)+offset);
        cot.lineTo((widthAndHeight*row)-offset,(j*widthAndHeight)+offset);
    }
    cot.stroke();
    $("#background").prepend(canvas);
    chessboard();

    //监听窗口关闭事件，当窗口关闭时，主动去关闭websocket连接，防止连接还没断开就关闭窗口，server端会抛异常。
    window.onbeforeunload = function(){
        websocket.close();
    };

    //关闭连接
    function closeWebSocket(){
        websocket.close();
    }

    window.addEventListener("load", initWebsocket, false);

});

//生成落子格子
function chessboard() 
{
    var str="", index = 0;
    for(var i=0; i<row; i++) {
        for(var j=0; j<col; j++) {
            str += "<div class=\"grid\" id=\"" + index + "\"></div>"; index++;
        }
    }
    $("#chess").empty();
    $("#chess").append(str);
    $("#chess").css({
        width:(row * widthAndHeight) + "px",
        height:(col * widthAndHeight) + "px",
        position: "absolute",
        top:"0px",
        left:"0px",
        "z-index":99999
    });
    $(".grid").on("click",function(){
        WuZiQi.playchess(this);
    });
    $(".grid").css({
        width:widthAndHeight+"px",
        height:widthAndHeight+"px"
    });
}

function initWebsocket() 
{
    //判断当前浏览器是否支持WebSocket
    if (!!window.WebSocket && window.WebSocket.prototype.send) {
        try{
            websocket = new WebSocket("ws://127.0.0.1:9501");
        } catch(err) {
            console.log(err);
        }
    }
    else{
        console.log('Not support websocket');
    }

    //连接发生错误的回调方法
    websocket.onerror = function(e){
        console.log(JSON.stringify(e));
    };

    //连接成功建立的回调方法
    websocket.onopen = function(event){
    };

    //接收到消息的回调方法(包含了聊天，落子，开始游戏)
    websocket.onmessage = function(event)
    {
        var result = JSON.parse(event.data);
        if(result.message != "")
        {
            $("#messagebox").append(result.message + "\n");
            //将多行文本滚动总是在最下方
            $("#messagebox").scrollTop($("#messagebox")[0].scrollHeight - $("#messagebox").height());
        }

        if(1 == result.status) // 游戏开始
        {
            chessboard();
            bout = result.bout; color = result.color;
        }
        else if(2 == result.status)
        {
            $("#"+result.xy).html("<div class=\"chessman "+result.color+"\"></div>");
            bout = result.bout;//落子后才改状态
            WuZiQi.isEnd(result.xy, result.color); 
        }
        else if(3 == result.status)
        {
            color = ""; bout = false;
            chessboard();
        }
    };

    //连接关闭的回调方法
    websocket.onclose = function(){
      console.log('onclose')
      setTimeout(function(){ initWebsocket(); }, 15000);
    };
}
