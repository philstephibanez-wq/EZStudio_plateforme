<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Local DEV facade/backend inventory. No PROD addresses are probed. */
final class DevHttpStatus
{
    private const TARGETS = [
        ['component'=>'ezscore','port'=>8502,'path'=>'/','kind'=>'facade'],
        ['component'=>'lab','port'=>8702,'path'=>'/','kind'=>'facade'],
        ['component'=>'lab','port'=>8701,'path'=>'/','kind'=>'backend'],
        ['component'=>'assistant','port'=>8712,'path'=>'/','kind'=>'facade'],
    ];
    public function snapshot(): array
    {
        $out=[];
        foreach (self::TARGETS as $target) {
            $socket=@stream_socket_client('tcp://127.0.0.1:'.$target['port'], $errno, $error, 0.35, STREAM_CLIENT_CONNECT);
            if (!is_resource($socket)) {
                $out[]=$target+['tcp_listening'=>false,'http_status'=>null,'http_reachable'=>false];
                continue;
            }
            if ($target['kind'] === 'backend') {
                fclose($socket);
                $out[]=$target+['tcp_listening'=>true,'http_status'=>null,'http_reachable'=>false];
                continue;
            }
            stream_set_timeout($socket,1,500000);
            $request='GET '.$target['path']." HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\nAccept: */*\r\n\r\n";
            $written=@fwrite($socket,$request);
            $line=$written===false ? false : @fgets($socket,512);
            fclose($socket);
            $status=is_string($line) && preg_match('~^HTTP/1\\.[01] ([1-5][0-9]{2})\\b~',trim($line),$match) ? (int)$match[1] : null;
            $out[]=$target+['tcp_listening'=>true,'http_status'=>$status,'http_reachable'=>$status!==null];
        }
        return $out;
    }
}
