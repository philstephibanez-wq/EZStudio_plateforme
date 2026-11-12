<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination;

/** Deterministic one-shot SSE serialization. No long-running PHP process. */
final class QueueEventStream
{
    public static function encode(array $events): string
    {
        $out="retry: 3000\n\n";
        foreach($events as $e){
            $id=filter_var($e['event_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false)throw new \InvalidArgumentException('invalid_event_id');
            $payload=json_encode($e,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $out.="id: ".$id."\nevent: queue.progress\ndata: ".$payload."\n\n";
        }
        return $out;
    }
}
