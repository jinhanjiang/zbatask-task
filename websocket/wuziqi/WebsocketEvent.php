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

use Zba\Timer;

class WebsocketEvent
{
    /**
     * $players = [
     *      id=>['roomId', 'opponentId']
     * ]
     */
    private static $players = [];

    /**
     * $rooms = [
     *      roomId=>[
     *         players=>[1, 2],
     *         status=>1 等待对手加入 2 等待开始 3 对战中 4 对战结束
     *      ]
     * ]
     */
    private static $rooms = [];

    private static $isAssigning = false;

    public static function onServerStart($ws)
    {
        Timer::add(5, function() use($ws) { self::autoAssignRoom($ws); }, array(), true);
        Timer::add(3, function() use($ws) { self::autoStartGame($ws); }, array(), true);
    }

    public static function autoStartGame($ws) {
        foreach(self::$rooms as $roomId=>$room) {
            if(2 == $room['status'] && 2 == count($room['players']))
            {
                list($player1, $player2) = $room['players'];
                // $ws->send($player1, json_encode(["status"=>3, "message"=>""]));
                // $ws->send($player2, json_encode(["status"=>3, "message"=>""]));
                // usleep(500);
                $ws->send($player1, json_encode([
                    "status"=>1, 
                    "bout" => true, 
                    "color"=>"black", 
                    "message"=>"已经开始，由我先落子！"
                ]));
                $ws->send($player2, json_encode([
                    "status"=>1,
                    "bout" => false, 
                    "color"=>"white", 
                    "message"=>"已经开始，由对方先落子！"
                ]));

                // 标记房间状态
                $room['status'] = 3;
                self::$rooms[$roomId] = $room;
            }
        }
    }

    public static function autoAssignRoom($ws) 
    {
        self::$isAssigning = true; $lastRoomId = -1;
        foreach(self::$players as $id=>$player) 
        {
            if(-1 == $player['opponentId'])
            {
                $rid = -1;
                if(($ct = count(self::$rooms)) > 0) {
                    foreach(self::$rooms as $roomId=>$room) {
                        $roomPlayers = $room['players'];
                        $rcnt = count($roomPlayers);
                        if(0 == $rcnt) unset(self::$rooms[$roomId]);
                        else if(1 == $rcnt && 1 == $room['status'] && ! in_array($id, $roomPlayers)) {
                            $rid = $roomId; break;
                        }
                    }
                }

                if(-1 == $rid) // 没有找到对手
                {
                    if(-1 == $player['roomId']) // 没有房间
                    {
                        if($ct > 0) {
                            $rkeys = array_keys(self::$rooms);
                            $lastRoomId = (int)end($rkeys);
                        }
                        $lastRoomId += 1;

                        self::$rooms[$lastRoomId]['players'][] = $id;
                        self::$rooms[$lastRoomId]['status'] = 1;
                        $player['roomId'] = $lastRoomId;
                    }
                }
                else // 找到对手
                {
                    // 自已有房间id（退出房间加入其他人房间）
                    if(-1 != $player['roomId']) unset(self::$rooms[$player['roomId']]);

                    // 设置我的房间号和对手
                    $player['roomId'] = $rid;
                    $player['opponentId'] = self::$rooms[$rid]['players'][0];

                    // 将对手的，对手id设置为我的id
                    self::$players[$player['opponentId']]['opponentId'] = $id;

                    // 我加入房间
                    self::$rooms[$rid]['players'][] = $id;

                    // 房间设置等待开始状态
                    self::$rooms[$rid]['status'] = 2;
                }
                self::$players[$id] = $player;
            }
        }
        self::$isAssigning = false;
    }

    /**
     * 当握手成功后回调
     */
    public static function onConnection($ws, $id) 
    {
        if($ws->connections[$id]['handshake']) {
            self::$players[$id] = ['roomId'=>-1, 'opponentId'=>-1];
            $ws->send($id, json_encode(["message"=> "等待其他人加入"]));
        }
    }

    /**
     * 处理提交的消息
     */
    public static function onMessage($ws, $id, $message)
    {
        $data = @json_decode($message, true);
        $data = is_array($data) ? $data : array();

        $player = isset(self::$players[$id]) ? self::$players[$id] : null;
        // 落子
        if(isset($data['xy']))
        {
            $rt = [
                'status'=>2,
                'xy'=>$data['xy'],
                'color'=>$data['color'],
            ];

            // 发送给我自已
            $ws->send($id, json_encode(
              $rt + ['bout'=>false, 'message'=>'系统：您已落子，请等待对手落子！']  
            ));
            // 发送给对手
            $ws->send($player['opponentId'], json_encode(
              $rt + ['bout'=>true, 'message'=>'系统：对手已落子，请您落子！']    
            ));
        }
        // 给所有人发通知
        else if(isset($data['notify']))
        {
            if(isset($data['notify'])) foreach(self::$players as $id=>$player) {
                $ws->send($id, json_encode(["message"=> $data['notify']]));
            }
        }
        // 发消息
        else if(isset($data['message']))
        {
            // 发送给我自已
            $ws->send($id, json_encode(['message'=>'我：'.$data['message']]));
            // 发送给对手
            $ws->send($player['opponentId'], json_encode(['message'=>'对手：'.$data['message']]));
        }
        // 游戏结束
        else if(isset($data['iswin']))
        {
            $roomId = self::$players[$id]['roomId'];
            if(! $data['iswin'])
            {
                // 输家退出桌面
                self::$rooms[$roomId]['players'] = [self::$players[$id]['opponentId']];
                self::$players[$id] = ['roomId'=>-1, 'opponentId'=>-1];

                if(3 == self::$rooms[$roomId]['status']) {
                    self::$rooms[$roomId]['status'] = 4;
                }

                $ws->send($id, json_encode(["status"=>3, "message"=>"流戏结束，我输了"]));
            }
            else
            {
                self::$rooms[$roomId]['status'] = 1;
                $ws->send($id, json_encode(["status"=>3, "message"=>"流戏结束，我赢了"]));
            }
        }
    }

    /**
     * 当链接关闭时回调
     */
    public static function onClose($ws, $id) {
        // while(! self::$isAssigning) { usleep(500); }
        $roomId = self::$players[$id]['roomId'];
        if(-1 != $roomId)
        {
            $opponentId = self::$players[$id]['opponentId'];
            if(-1 == $opponentId) unset(self::$rooms[$roomId]);
            else 
            {
                $ws->send($opponentId, json_encode(["status"=>3, "message"=>"对手离开了对战"]));

                self::$rooms[$roomId]['players'] = array($opponentId);
                self::$rooms[$roomId]['status'] = 1;
            }
        }
        unset(self::$players[$id]);

        if(count(self::$players) == 0) self::$rooms = [];
    }

}