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

/**
 * 当前例子前端页面效果
 * https://github.com/KMKNKK/Chatroom-WebSocket
 */
class WebsocketEvent
{
    public static $users = [];

    /**
     * 处理提交的消息
     */
    public static function onMessage($ws, $id, $message)
    {
        $data = @json_decode($message, true);
        $data = is_array($data) ? $data : array();

        switch($data['ac'])
        {
            case 'login':
                $isRepeat = false;
                foreach(self::$users as $uid=>$user) {
                    if($user['username'] == $data['username']) {
                        $isRepeat = true; break;
                    }
                }
                if($isRepeat)
                {
                    $ws->send($id, json_encode([
                        'ac'=>'loginFail',
                        'message'=>'昵称重复',
                    ]));
                }
                else
                {
                    self::$users[$id] = ['username'=>$data['username']];

                    // 登录成功
                    $ws->send($id, json_encode([
                        'ac'=>'loginSuccess',
                        'username'=>$data['username'],
                    ]));

                    foreach(self::$users as $uid=>$user) {
                        if($id == $uid) continue;
                        $ws->send($uid, json_encode([
                            'ac'=>'add',
                            'username'=>$data['username'],
                        ]));    
                    }
                }
                break;

            case 'sendMessage':
                foreach(self::$users as $uid=>$user) {
                    $ws->send($uid, json_encode([
                        'ac'=>'receiveMessage',
                        'date'=>date('m-d H:i:s')
                    ] + $data));    
                }
                break;

            case 'sendImg':
                foreach(self::$users as $uid=>$user) {
                    $ws->send($uid, json_encode([
                        'ac'=>'receiveImage',
                        'date'=>date('m-d H:i:s'),
                    ] + $data));    
                }
                break;
        }
        
    }

    /**
     * 当链接关闭时回调
     */
    public static function onClose($ws, $id) {
        foreach(self::$users as $uid=>$user) {
            if($id == $uid) continue;
            $ws->send($uid, json_encode([
                'ac'=>'leave',
                'username'=>self::$users[$id]['username'],
            ]));    
        }
        unset(self::$users[$id]);
    }

}