<?php

namespace Noxlogic\PhpotonBundle\Websocket;


use WebSocket\ConnectionException;

class Client extends \WebSocket\Client
{

    protected function read($length)
    {
        $data = '';
        while (strlen($data) < $length) {
            $read = array($this->socket);
            $write = null;
            $except = null;
            $i = stream_select($read, $write, $except, 1);
            if ($i == 0) {
                throw new TimeoutException();
            }
            foreach ($read as $sock) {
                $buffer = fread($sock, $length - strlen($data));
            }

            if ($buffer === false) {
                $metadata = stream_get_meta_data($this->socket);
                throw new ConnectionException(
                    'Broken frame, read ' . strlen($data) . ' of stated ' .
                    $length . ' bytes.  Stream state: ' .
                    json_encode($metadata)
                );
            }
            if ($buffer !== '') {
                $data .= $buffer;
            }
        }

        return $data;
    }

}
