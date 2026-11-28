<?php
declare(strict_types=1);
namespace Shared\Quota;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
final class QuotaController {
    public function __construct(private readonly string $root, private readonly string $environment) {}
    private function path(): string { return $this->root.'/var/ezs_quota'.($this->environment === 'test' ? '_test' : '').'.json'; }
    private function local(Request $r): bool { return in_array($r->getClientIp(), ['127.0.0.1','::1'],true) && in_array($r->server->get('REMOTE_ADDR'), ['127.0.0.1','::1'],true); }
    private function answer(array $data,int $status=200): JsonResponse { return new JsonResponse($data,$status,['Cache-Control'=>'private, no-store']); }
    #[Route('/chatgpt/quota/status', name:'ezs_quota_status', methods:['GET'])]
    public function status(Request $r): JsonResponse {
        if (!$this->local($r)) return $this->answer(['error'=>'local_only'],403);
        $session=$r->getSession();
        $token=$session->get('_ezs_quota_csrf');
        if (!is_string($token)) { $token=bin2hex(random_bytes(32)); $session->set('_ezs_quota_csrf',$token); }
        require_once __DIR__.'/AttemptLedger.php';
        try { $rows=AttemptLedger::rows($this->root,$this->environment === 'test'); $data=QuotaReading::load($this->path()); if ($this->environment !== 'test') { require_once __DIR__.'/CodexQuotaProvider.php'; unset($data['five_hours'],$data['weekly']); $data=array_replace($data,CodexQuotaProvider::read($this->root)); } return $this->answer(['scopes'=>QuotaReading::view($data,time()),'csrf'=>$token,'active'=>array_values(array_map(static fn($v)=>array_intersect_key($v,array_flip(['job','song','run','revision','stage','stage_at','started_at'])),array_filter($rows??[],static fn($v)=>($v['status']??null)==='open'))),'statistics'=>AttemptLedger::stats($rows)]); }
        catch (\Throwable) { return $this->answer(['error'=>'unavailable'],503); }
    }
    #[Route('/chatgpt/quota/observation', name:'ezs_quota_observation', methods:['POST'])]
    public function save(Request $r): JsonResponse {
        if (!$this->local($r)) return $this->answer(['error'=>'local_only'],403);
        $token=$r->getSession()->get('_ezs_quota_csrf');
        if (!is_string($token) || !hash_equals($token,(string)$r->headers->get('X-EZS-Quota-CSRF',''))) return $this->answer(['error'=>'csrf'],403);
        if (strlen($r->getContent())>8192 || !str_starts_with((string)$r->headers->get('Content-Type'),'application/json')) return $this->answer(['error'=>'invalid'],400);
        try { $data=json_decode($r->getContent(),true,16,JSON_THROW_ON_ERROR); $new=QuotaReading::validate($data,time()); QuotaReading::save($this->path(),$new); return $this->answer(['ok'=>true]); }
        catch (\InvalidArgumentException|\JsonException) { return $this->answer(['error'=>'invalid'],400); }
        catch (\Throwable) { return $this->answer(['error'=>'unavailable'],503); }
    }
}
