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

class WebsocketEvent
{
    public static function onMessage($ws, $id, $message)
    {
        $onlinecnt = count($ws->connections);
        $ws->send($id, "myid: {$id}, online: {$onlinecnt}, message: {$message}");

        foreach($ws->connections as $cid=>$conn) {
            if($cid != $id) $ws->send($cid, "id: {$id}, online: {$onlinecnt}, message: {$message}");
        }
    }


}