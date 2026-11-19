<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination\Controller;

use Ezs\Web\Module\Coordination\GlobalQueueLocation;
use Ezs\Web\Module\Coordination\QueueRestAccess;
use Ezs\Web\Module\Coordination\QueueRestReadModel;
use Ezs\Web\Module\Coordination\QueueEventStream;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class QueueReadController
{
    #[Route('/api/v2/queue/jobs',name:'ezs_queue_jobs_read',methods:['GET'])]
    public function jobs(Request $request):JsonResponse
    {
        return $this->respond($request,static fn(QueueRestReadModel $v,string $p):array=>['jobs'=>$v->jobs($p)]);
    }
    #[Route('/api/v2/queue/jobs/{id<\d+>}',name:'ezs_queue_job_read',methods:['GET'])]
    public function job(Request $request,int $id):JsonResponse
    {
        return $this->respond($request,static function(QueueRestReadModel $v,string $p)use($id):array{
            $row=$v->job($p,$id);
            return $row===null?['error'=>'not_found']:['job'=>$row];
        });
    }
    #[Route('/api/v2/queue/events',name:'ezs_queue_events_read',methods:['GET'])]
    public function events(Request $request):JsonResponse
    {
        $after=filter_var($request->query->get('after','0'),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if($after===false)return $this->json(['error'=>'invalid_cursor'],400);
        return $this->respond($request,static fn(QueueRestReadModel $v,string $p):array=>['events'=>$v->events($p,(int)$after)]);
    }
    #[Route('/api/v2/queue/events/stream',name:'ezs_queue_events_stream',methods:['GET'])]
    public function stream(Request $request):Response
    {
        $producer=QueueRestAccess::producerFor($request->headers->get('Authorization'));
        if($producer===null)return $this->json(['error'=>'unauthorized'],401);
        $cursor=$request->headers->get('Last-Event-ID') ?? $request->query->get('after','0');
        $after=filter_var($cursor,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if($after===false)return $this->json(['error'=>'invalid_cursor'],400);
        try{
            $events=(new QueueRestReadModel(GlobalQueueLocation::path()))->events($producer,(int)$after);
            return new Response(QueueEventStream::encode($events),200,[
                'Content-Type'=>'text/event-stream; charset=utf-8',
                'Cache-Control'=>'private, no-store',
                'X-Accel-Buffering'=>'no',
                'X-Content-Type-Options'=>'nosniff',
            ]);
        }catch(\Throwable){return $this->json(['error'=>'queue_unavailable'],503);}
    }

    private function respond(Request $request,\Closure $f):JsonResponse
    {
        $producer=QueueRestAccess::producerFor($request->headers->get('Authorization'));
        if($producer===null)return $this->json(['error'=>'unauthorized'],401);
        try{
            $view=new QueueRestReadModel(GlobalQueueLocation::path());
            $result=$f($view,$producer);
            if(isset($result['error']) && $result['error']==='not_found')return $this->json($result,404);
            return $this->json(['schema'=>'ezs.queue.read.v1','producer'=>$producer]+$result);
        }catch(\Throwable){return $this->json(['error'=>'queue_unavailable'],503);}
    }
    private function json(array $value,int $code=200):JsonResponse
    {
        return new JsonResponse($value,$code,['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
