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
    private $maxRead = 4096;

    public $connections = [];

    public function __construct($config=array()) {
        if(! extension_loaded('event')) {
            throw new \Exception('Event extension needs to be install to run the current service');
        }
        $this->base = new EventBase();
        if(! $this->base) {
            ProcessException::error("Couldn't open event base"); exit;
        }
        // host setting
        if(isset($config['host']) && preg_match('/^\d{1,3}(\.\d{1,3}){3}:\d{1,5}$/', $config['host'])) {
            // for example: 0.0.0.0:1223
            $host = $config['host'];
        } else if (isset($config['port']) && preg_match('/^\d{1,5}$/', $config['port'])) {
            $host = "0.0.0.0:{$config['port']}";
        } else {
            $host = "0.0.0.0:1223";
        }
        // maxRead
        $this->maxRead = (isset($config['maxRead']) 
            && preg_match('/^\d+$/', $config['maxRead']) 
            && $config['maxRead'] > 0) ? $config['maxRead'] : 4096;
        // Initial link
        $this->listener = new EventListener(
            $this->base, 
            [$this, "acceptConnCallback"],
            $this->base,
            EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE, 
            -1, 
            $host
        );
        if(! $this->listener) {
            ProcessException::error("Couldn't create listener");
        } 
        else {
            $this->listener->setErrorCallback(array($this, "acceptErrorCallback"));
            if(is_callable(__NAMESPACE__.'\WebsocketEvent::onServerStart')) {
                try{
                    $content = call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onServerStart', array($this));
                } catch(\Exception $ex) {
                    ProcessException::info($ex->getMessage());
                } 
            }
        }
    }

    public function start() {
        $this->base->loop(EventBase::LOOP_NONBLOCK);
    }

    public function close($id) {
        if(is_callable(__NAMESPACE__.'\WebsocketEvent::onClose')) {
            try{
                call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onClose', array($this, $id));
            } catch(\Exception $ex) {
                ProcessException::info($ex->getMessage());
            } 
        }
        if(isset($this->connections[$id])) {
            if($this->connections[$id]['bev']) {
                $this->connections[$id]['bev']->disable(Event::READ | Event::WRITE);
                $this->connections[$id]['bev']->free();
            }
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
        $id = $this->getConnectionId();

        // Whether the link handshake
        $this->connections[$id]['handshake'] = false;

        // Process packets from the client
        $this->connections[$id]['handlePacket'] = false;
        $this->connections[$id]['partbuffer'] = '';
        $this->connections[$id]['partmessage'] = '';

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
            $buffRead = $bev->input->read($this->maxRead);
            if(! $this->connections[$id]['handshake']) {
                $this->handshake($id, $buffRead);
            } 
            else if($buffRead) {
                $this->splitPacket($id, $buffRead);
            }
        }
    }
    
    public function evEventCallback($bev, $event, $id) {
        if($event & EventBufferEvent::ERROR) ProcessException::error("Error from buffereven");
        if($event & (EventBufferEvent::EOF | EventBufferEvent::ERROR)) $this->close($id);
    }

    public function send($id, $message, $messageType='text') {
        $message = $this->frame($id, $message, $messageType);
        if($message && isset($this->connections[$id]) && $this->connections[$id]['bev']) {
            $this->connections[$id]['bev']->write($message);
        }
    }

    private function getConnectionId() {
        $maxUnsignedInt = 4294967295;
        if (self::$id >= $maxUnsignedInt) self::$id = 0;
        while(++self::$id <= $maxUnsignedInt) {
            if(! isset($this->connections[self::$id])) break;
        }
        return self::$id;
    }

    private function handshake($id, $buffer)
    {
        if(is_callable(__NAMESPACE__.'\WebsocketEvent::onHandshake')) {
            try{
                $content = call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onHandshake', array($this, $id, $buffer));
            } catch(\Exception $ex) {
                ProcessException::info($ex->getMessage());
            } 
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
            $this->connections[$id]['bev']->write($upgrade);
            $this->connections[$id]['handshake'] = true;
            if(is_callable(__NAMESPACE__.'\WebsocketEvent::onConnection')) {
                try{
                    call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onConnection', array($this, $id));
                } catch(\Exception $ex) {
                    ProcessException::info($ex->getMessage());
                } 
            }
        }
        else if(is_callable(__NAMESPACE__.'\WebsocketEvent::onMessage')) {
            try{
                call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onMessage', array($this, $id, $buffer));
            } catch(\Exception $ex) {
                ProcessException::info($ex->getMessage());
            } 
        }
    }

    private function splitPacket($id, $packet)
    {
        $length = strlen($packet);
        if($this->connections[$id]['handlePacket']) {
            $packet = $this->connections[$id]['partbuffer'] . $packet;
            $length = strlen($packet);
            $this->connections[$id]['handlePacket'] = false;
            $this->connections[$id]['partbuffer'] = '';
        }

        $fullPacket = $packet;
        $framePos = 0; $frameId = 1;
        while($framePos < $length) {
            $headers = $this->getPacketHeaders($packet);
            $hadersSize = $this->calcOffset($headers);
            $frameSize = (int)$headers['length'] + $hadersSize;

            $frame = substr($fullPacket, $framePos, $frameSize);
            if(($message = $this->deframe($id, $frame)) !== false)
            {
                if(is_callable(__NAMESPACE__.'\WebsocketEvent::onMessage')) {
                    try{
                        call_user_func_array(__NAMESPACE__.'\WebsocketEvent::onMessage', array($this, $id, $message));
                    } catch(\Exception $ex) {
                        ProcessException::info($ex->getMessage());
                    } 
                }
            }
            $framePos += $frameSize;
            $packet = substr($fullPacket, $framePos);
            $frameId ++;
        }
    }

    /* 
      0                   1                   2                   3
      0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
     +-+-+-+-+-------+-+-------------+-------------------------------+
     |F|R|R|R| opcode|M| Payload len |    Extended payload length    |
     |I|S|S|S|  (4)  |A|     (7)     |             (16/64)           |
     |N|V|V|V|       |S|             |   (if payload len==126/127)   |
     | |1|2|3|       |K|             |                               |
     +-+-+-+-+-------+-+-------------+ - - - - - - - - - - - - - - - +
     |     Extended payload length continued, if payload len == 127  |
     + - - - - - - - - - - - - - - - +-------------------------------+
     |                               |Masking-key, if MASK set to 1  |
     +-------------------------------+-------------------------------+
     | Masking-key (continued)       |          Payload Data         |
     +-------------------------------- - - - - - - - - - - - - - - - +
     :                     Payload Data continued ...                :
     + - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - +
     |                     Payload Data continued ...                |
     +---------------------------------------------------------------+
     */
    private function getPacketHeaders($message) 
    {
        if("" == $message) return array();
        $header = array(
            'fin'     => $message[0] & chr(128),
            'rsv1'    => $message[0] & chr(64),
            'rsv2'    => $message[0] & chr(32),
            'rsv3'    => $message[0] & chr(16),
            'opcode'  => ord($message[0]) & 15,
            'hasmask' => $message[1] & chr(128),
            'length'  => 0,
            'mask'    => ""
        );
        $header['length'] = (ord($message[1]) >= 128) ? ord($message[1]) - 128 : ord($message[1]);
        if ($header['length'] == 126) {
            if ($header['hasmask']) {
                $header['mask'] = $message[4] . $message[5] . $message[6] . $message[7];
            }
            $header['length'] = ord($message[2]) * 256 + ord($message[3]);
        } 
        else if ($header['length'] == 127) 
        {
            if ($header['hasmask']) {
                $header['mask'] = $message[10] . $message[11] . $message[12] . $message[13];
            }
            $header['length'] = ord($message[2]) * 65536 * 65536 * 65536 * 256 
                + ord($message[3]) * 65536 * 65536 * 65536
                + ord($message[4]) * 65536 * 65536 * 256
                + ord($message[5]) * 65536 * 65536
                + ord($message[6]) * 65536 * 256
                + ord($message[7]) * 65536 
                + ord($message[8]) * 256
                + ord($message[9]);
        } 
        else if ($header['hasmask']) {
            $header['mask'] = $message[2] . $message[3] . $message[4] . $message[5];
        }
        return $header;
    }

    private function calcOffset($headers) {
        $offset = 2;
        if ($headers['hasmask']) {
            $offset += 4;
        }
        if ($headers['length'] > 65535) {
            $offset += 8;
        } elseif ($headers['length'] > 125) {
            $offset += 2;
        }
        return $offset;
    }

    public function frame($id, $message, $messageType='text') 
    {
        switch ($messageType) {
            case 'continuous':
                $b1 = 0;
                break;
            case 'text':
                $b1 = 1;
                break;
            case 'binary':
                $b1 = 2;
                break;
            case 'close':
                $b1 = 8;
                break;
            case 'ping':
                $b1 = 9;
                break;
            case 'pong':
                $b1 = 10;
                break;
        }
        $b1 += 128;
        
        $length = strlen($message);
        $lengthField = "";
        if ($length < 126) {
            $b2 = $length;
        } 
        else if ($length < 65536) 
        {
            $b2 = 126;
            $hexLength = dechex($length);
            if (strlen($hexLength) % 2 == 1) {
                $hexLength = '0' . $hexLength;
            } 
            $n = strlen($hexLength) - 2;
            for ($i = $n; $i >= 0; $i = $i - 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while (strlen($lengthField) < 2) {
                $lengthField = chr(0) . $lengthField;
            }
        } 
        else 
        {
            $b2 = 127;
            $hexLength = dechex($length);
            if (strlen($hexLength) % 2 == 1) {
                $hexLength = '0' . $hexLength;
            } 
            $n = strlen($hexLength) - 2;
            for ($i = $n; $i >= 0; $i = $i - 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while (strlen($lengthField) < 8) {
                $lengthField = chr(0) . $lengthField;
            }
        }
        return chr($b1) . chr($b2) . $lengthField . $message;
    }

    public function deframe($id, $message) 
    {
        $headers = $this->getPacketHeaders($message);
        if(! $headers) return false;
        $pong = $close = false;
        switch($headers['opcode']) {
            case 0:
            case 1:
            case 2:
                break;
            case 8: 
                $this->close($id);
                return false;
            case 9:
                $pong = true;
            case 10:
                break;
            default: //fail connection
                $close = true;
                break;
        }
        if ($close) return false;
        $payload = $this->connections[$id]['partmessage'] . $this->extractPayload($message, $headers);
        if ($pong) {
            $this->send($id, $payload, 'pong');
            return false;
        }
        if ($headers['length'] > strlen($this->applyMask($payload, $headers))) {
            $this->connections[$id]['handlePacket'] = true;
            $this->connections[$id]['partbuffer'] = $message;
            return false;
        }
        $payload = $this->applyMask($payload, $headers);
        if ($headers['fin']) {
            $this->connections[$id]['partmessage'] = "";
            return $payload;
        }
        $this->connections[$id]['partmessage'] = $payload;
        return false;
    }

    private function extractPayload($message, $headers) 
    {
        $offset = 2;
        if ($headers['hasmask']) {
            $offset += 4;
        }
        if ($headers['length'] > 65535) {
            $offset += 8;
        } 
        else if ($headers['length'] > 125) {
            $offset += 2;
        }
        return substr($message, $offset);
    }

    private function applyMask($payload, $headers) {
        $effectiveMask = "";
        if ($headers['hasmask']) {
            $mask = $headers['mask'];
        } 
        else {
            return $payload;
        }
        while (strlen($effectiveMask) < strlen($payload)) {
            $effectiveMask .= $mask;
        }
        while (strlen($effectiveMask) > strlen($payload)) {
            $effectiveMask = substr($effectiveMask, 0, -1);
        }
        return $effectiveMask ^ $payload;
    }

    private function printHeaders($headers) {
        $str = "Array\n(\n";
        foreach ($headers as $key => $value) {
            if ($key == 'length' || $key == 'opcode') {
                $str .= "\t[$key] => $value\n";
            } 
            else {
                $str .= "\t[$key] => ".$this->strtohex($value);
            }
        }
        $str .= ")\n\n";
        return $str;
    }

    private function strtohex($str) {
        $strout = "";
        for ($i = 0; $i < strlen($str); $i++) {
            $strout .= (ord($str[$i]) < 16) ? "0" . dechex(ord($str[$i])) : dechex(ord($str[$i]));
            $strout .= " ";
            if ($i%32 == 7) $strout .= ": ";
            if ($i%32 == 15) $strout .= ": ";
            if ($i%32 == 23) $strout .= ": ";
            if ($i%32 == 31) $strout .= "\n";
        }
        return $strout . "\n";
    }

}