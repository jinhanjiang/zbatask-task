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

use \Event;
use \EventBufferEvent;
use \EventListener;
use \EventBase;
use \EventUtil;

use Zba\ProcessException;

class WebsocketServer
{
    private $base, $listener;
    private static $id = 0;
    private $maxRead = 65535;

    public $connections = [];

    public function __construct($host = "0.0.0.0:1223") {
        if(! extension_loaded('event')) {
            throw new \Exception('Event extension needs to be install to run the current service');
        }
        $this->base = new EventBase();
        if(! $this->base) {
            ProcessException::error("Couldn't open event base"); exit;
        }
        $this->listener = new EventListener(
            $this->base, 
            [$this, "acceptConnCallback"],
            $this->base,
            EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE, 
            -1, 
            $host
        );
        if(! $this->listener) {
            ProcessException::error("Couldn't create listener"); exit;
        }
        $this->listener->setErrorCallback(array($this, "acceptErrorCallback"));
        if(is_callable(__NAMESPACE__.'\WebsocketEvent::onServerStart')) {
            try{
                $content = call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onServerStart', array($this));
            } catch(\Exception $ex) {} 
        }
    }

    public function start() {
        $this->base->loop(EventBase::LOOP_NONBLOCK);
    }

    public function close($id) {
        if(is_callable(__NAMESPACE__.'\WebsocketEvent::onClose')) {
            try{
                call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onClose', array($this, $id));
            } catch(\Exception $ex) {} 
        }
        if(isset($this->connections[$id])) {
            $this->connections[$id]['bev']->disable(Event::READ | Event::WRITE);
            $this->connections[$id]['bev']->free();
            unset($this->connections[$id]);
        }
    }

    public function closeAll() {
        foreach($this->connections as $id=>$conn) {
            $this->close($id);
        }
        $this->listener->disable();
        $this->base->stop();
    }

    public function acceptConnCallback($listener, $fd, $address, $ctx) {
        $id = self::$id ++;
        $this->connections[$id]['handshake'] = false;
        $this->connections[$id]['data'] = '';
        $this->connections[$id]['data1'] = '';
        $this->connections[$id]['data2'] = '';
        $this->connections[$id]['bev'] = new EventBufferEvent($this->base, $fd, EventBufferEvent::OPT_CLOSE_ON_FREE);

        if(! $this->connections[$id]['bev']) {
            ProcessException::error("Failed creating buffer");
        }
        else
        {
            $this->connections[$id]['bev']->setCallbacks(
                [$this, 'evReadCallback'], 
                NULL,
                [$this, 'evEventCallback'],
                $id
            );
            $this->connections[$id]['bev']->enable(Event::READ | Event::WRITE);
        }
    }

    public function acceptErrorCallback($listener, $ctx) {
        ProcessException::error(printf("Got an error %d (%s) on the listener. Shutting down.\n",
            EventUtil::getLastSocketErrno(),
            EventUtil::getLastSocketError()
        ));
        $this->base->exit(NULL);
    }

    public function evReadCallback($bev, $id) {
        while($bev->input->length > 0) {
            $this->connections[$id]['data1'] .= $bev->input->read($this->maxRead);
            $data1len = strlen($this->connections[$id]['data1']);
            if($this->connections[$id]['data1'][$data1len - 1] == "\n" && 
                $this->connections[$id]['data1'][$data1len - 2] == "\r") {
               $this->connections[$id]['data'] =  $this->connections[$id]['data1'];
            }

            $this->connections[$id]['data2'] .= $this->decode($this->connections[$id]['data1']);
            $data2len = strlen($this->connections[$id]['data2']);
            if(! $this->connections[$id]['data'] && 
                $this->connections[$id]['data2'][$data2len - 1] == "\n" && 
                $this->connections[$id]['data2'][$data2len - 2] == "\r") {
               $this->connections[$id]['data'] =  $this->connections[$id]['data2'];
            }

            if($this->connections[$id]['data']) {
                $line = substr($this->connections[$id]['data'], 0, strlen($this->connections[$id]['data']) - 2);
                $this->connections[$id]['data'] = '';
                $this->connections[$id]['data1'] = '';
                $this->connections[$id]['data2'] = '';

                if(! $this->connections[$id]['handshake']) {
                    $this->handshake($id, $line);
                } else if(is_callable(__NAMESPACE__.'\WebsocketEvent::onMessage')) {
                    try{
                        call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onMessage', array($this, $id, $line));
                    } catch(\Exception $ex) {} 
                }
            }
        }
    }
    
    public function evEventCallback($bev, $event, $id) {
        if($event & EventBufferEvent::ERROR) ProcessException::error("Error from buffereven");
        if($event & (EventBufferEvent::EOF | EventBufferEvent::ERROR)) $this->close($id);
    }

    public function send($id, $message, $encode=true) {
        if($encode) {
            $message = $this->encode($message);
        }
        if(isset($this->connections[$id])) {
            $this->connections[$id]['bev']->write($message);
        }
    }

    private function handshake($id, $buffer)
    {
        if(is_callable(__NAMESPACE__.'\WebsocketEvent::onHandshake')) {
            try{
                $content = call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onHandshake', array($this, $id, $buffer));
            } catch(\Exception $ex) {} 
        }
        $buffer = $content ? $content : $buffer;
        preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $buffer, $match);
        if(isset($match[1]))
        {
            $upgrade = "HTTP/1.1 101 Switching Protocol\r\n" .
                "Upgrade: websocket\r\n" .
                "Sec-WebSocket-Version: 13\r\n" .
                "Connection: Upgrade\r\n" .
                "Sec-WebSocket-Accept: " . base64_encode(sha1($match[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)) . "\r\n\r\n";
            $this->send($id, $upgrade, false);
            $this->connections[$id]['handshake'] = true;
            if(is_callable(__NAMESPACE__.'\WebsocketEvent::onConnection')) {
                try{
                    call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onConnection', array($this, $id));
                } catch(\Exception $ex) {} 
            }
        }
        else if(is_callable(__NAMESPACE__.'\WebsocketEvent::onMessage')) {
            try{
                call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onMessage', array($this, $id, $buffer));
            } catch(\Exception $ex) {} 
        }
    }

    private function encode($buffer)
    {
        $len = strlen($buffer);
        if ($len <= 125) {
            return "\x81" . chr($len) . $buffer;
        } else if ($len <= 65535) {
            return "\x81" . chr(126) . pack("n", $len) . $buffer;
        } else {
            return "\x81" . char(127) . pack("xxxxN", $len) . $buffer;
        }
    }

    private function decode($buffer)
    {
        $masks = $data = $decoded = null;
        $len = ord($buffer[1]) & 127;

        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return $decoded;
    }

}