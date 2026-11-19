<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination;

use PDO;

final class QueueRestReadModel
{
    private PDO $db;
    public function __construct(string $path)
    {
        if(!is_file($path))throw new \RuntimeException('queue_database_missing');
        $uri='file:'.str_replace(['%','?','#'],['%25','%3F','%23'],str_replace('\\','/',$path)).'?mode=ro';
        $this->db=new PDO('sqlite:'.$uri,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>2]);
        $this->db->exec('PRAGMA query_only=ON');
    }
    public function jobs(string $producer):array
    {
        $q=$this->db->prepare('SELECT id,producer,external_id,kind,resource_class,priority,state,claim_generation,created_at,updated_at FROM global_queue_jobs WHERE producer=? ORDER BY updated_at DESC,id DESC LIMIT 100');
        $q->execute([$producer]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function job(string $producer,int $id):?array
    {
        $q=$this->db->prepare('SELECT id,producer,external_id,kind,resource_class,priority,state,claim_generation,created_at,updated_at FROM global_queue_jobs WHERE producer=? AND id=?');
        $q->execute([$producer,$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function events(string $producer,int $after):array
    {
        if($after<0)throw new \InvalidArgumentException('invalid_cursor');
        $q=$this->db->prepare('SELECT event_id,job_id,event_type,progress_percent,phase,message,created_at FROM global_queue_events WHERE producer=? AND event_id>? ORDER BY event_id ASC LIMIT 100');
        $q->execute([$producer,$after]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
