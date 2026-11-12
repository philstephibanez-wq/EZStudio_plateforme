<?php
namespace EZSAssistant\Controller;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
final class DashboardController extends AbstractController {
    public function __construct(private readonly LoggerInterface $logger) {}
    #[Route('/historique', name: 'assistant_dashboard_legacy', methods: ['GET'])]
    public function index(): Response {
        $root = dirname(__DIR__, 2);
        return $this->render('dashboard/index.html.twig', ['version' => trim((string) @file_get_contents($root.'/VERSION'))]);
    }
    #[Route('/api/health', name: 'assistant_health', methods: ['GET'])]
    public function health(): JsonResponse {
        return $this->json(['ok'=>true,'service'=>'EZStudioLab Assistant','tier'=>'web','database'=>false,'persistence_owner'=>'LAB','version'=>trim((string) @file_get_contents(dirname(__DIR__, 2).'/VERSION'))]);
    }
    #[Route('/api/inference/status', name: 'assistant_inference_status', methods: ['GET'])]
    public function inferenceStatus(): JsonResponse {
        $request = static function (string $route): ?array {
            $context = stream_context_create(['http'=>['method'=>'GET','timeout'=>3,
                'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"]]);
            $body = @file_get_contents('http://127.0.0.1:8720'.$route, false, $context);
            if ($body === false) return null;
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        };
        $health = $request('/health');
        $ready = ($health['status'] ?? null) === 'ok';
        $props = $ready ? $request('/props') : null;
        $models = $ready ? $request('/v1/models') : null;
        $model = $models['data'][0]['id'] ?? $props['default_generation_settings']['model'] ?? null;
        $build = $props['build_info'] ?? $props['version'] ?? null;
        return $this->json([
            'ok'=>$ready, 'state'=>$ready?'READY':'UNAVAILABLE',
            'engine'=>'llama.cpp', 'engine_version'=>is_string($build)?$build:null,
            'model'=>is_string($model)?$model:null, 'endpoint'=>'127.0.0.1:8720',
            'health'=>$health, 'generation_verified'=>false,
        ], $ready?200:503);
    }

    #[Route('/api/status', name: 'assistant_status', methods: ['GET'])]
    public function status(): JsonResponse {
        $root = dirname(__DIR__, 2);
        $python = $_ENV['EZSTUDIOLAB_ASSISTANT_PYTHON'] ?? $_SERVER['EZSTUDIOLAB_ASSISTANT_PYTHON'] ?? 'python';
        $process = new Process([$python, $root.'/app/web_status.py']);
        $process->setTimeout(8.0);
        try {
            $process->run();
            if (!$process->isSuccessful()) throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'python_status_failed');
            $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new \RuntimeException('python_status_not_object');
            return $this->json($payload);
        } catch (\Throwable $e) {
            $this->logger->error('Assistant Python status failed', ['exception'=>$e]);
            return $this->json(['ok'=>false,'state'=>'ERROR','error'=>$e->getMessage()], 503);
        }
    }
    #[Route('/api/profile/latest-review', name: 'assistant_profile_latest_review', methods: ['GET'])]
    public function latestProfileReview(\Symfony\Component\HttpFoundation\Request $request): JsonResponse {
        $root = dirname(__DIR__, 2);
        $python = $_ENV['EZSTUDIOLAB_ASSISTANT_PYTHON'] ?? $_SERVER['EZSTUDIOLAB_ASSISTANT_PYTHON'] ?? 'python';
        $runId = (string) $request->query->get('run_id', '');
        if ($runId !== '' && (!ctype_digit($runId) || (int) $runId < 1)) {
            return $this->json(['ok'=>false,'error'=>'run_id_invalid'],422);
        }
        $env = $runId !== '' ? ['EZSTUDIOLAB_PROFILE_RUN_ID'=>$runId] : [];
        $process = new Process([$python, $root.'/runtime/profile_latest_review.py'], $root, $env);
        $process->setTimeout(35.0);
        try {
            $process->run();
            $output = trim($process->getOutput());
            if (!$process->isSuccessful()) {
                $this->logger->error('LAB review Python exited unsuccessfully', [
                    'exit_code'=>$process->getExitCode(),
                    'stderr'=>substr($process->getErrorOutput(), 0, 1200),
                ]);
                return $this->json(['ok'=>false,'error'=>'latest_review_python_failed',
                    'exit_code'=>$process->getExitCode()],503);
            }
            if ($output === '') {
                $this->logger->error('LAB review Python returned empty output', [
                    'stderr'=>substr($process->getErrorOutput(), 0, 1200),
                ]);
                return $this->json(['ok'=>false,'error'=>'latest_review_empty_output'],503);
            }
            try {
                $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $jsonError) {
                $this->logger->error('LAB review Python returned invalid JSON', [
                    'exception'=>$jsonError,
                    'stdout_bytes'=>strlen($output),
                    'stderr'=>substr($process->getErrorOutput(), 0, 1200),
                ]);
                return $this->json(['ok'=>false,'error'=>'latest_review_invalid_json'],503);
            }
            if (!is_array($result)) {
                return $this->json(['ok'=>false,'error'=>'latest_review_invalid_payload'],503);
            }
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->error('Latest LAB review transport failed', ['exception'=>$e]);
            return $this->json(['ok'=>false,'error'=>'latest_review_transport_error','reason'=>($e instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException ? 'process_timeout' : get_class($e))],503);
        }
    }

    #[Route('/api/profile/direct-research', name: 'assistant_profile_direct_research', methods: ['POST'])]
    public function directResearch(\Symfony\Component\HttpFoundation\Request $request): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) return $this->json(['ok'=>false,'error'=>'invalid_json'],400);
        $title = trim((string) ($data['title'] ?? ''));
        $artist = trim((string) ($data['artist'] ?? ''));
        $items = $data['items'] ?? [];
        if ($title === '' || $artist === '' || strlen($title)>720 || strlen($artist)>720
            || !is_array($items) || count($items)<1 || count($items)>10) {
            return $this->json(['ok'=>false,'error'=>'invalid_research_request'],400);
        }
        $root = dirname(__DIR__, 2);
        $python = $_ENV['EZSTUDIOLAB_ASSISTANT_PYTHON'] ?? $_SERVER['EZSTUDIOLAB_ASSISTANT_PYTHON'] ?? 'python';
        $process = new Process([$python, $root.'/runtime/profile_direct_job.py'], $root,
            null, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $process->setTimeout(12.0);
        try {
            $process->run();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($result)) throw new \RuntimeException('invalid_python_response');
            if (!$process->isSuccessful()) {
                return $this->json(['ok'=>false,'error'=>$result['error'] ?? 'research_failed',
                    'reason'=>$result['reason'] ?? 'Recherche indisponible'],503);
            }
            return $this->json($result);
        } catch (\Throwable $e) {
            $this->logger->error('PROFILE direct research failed', ['exception'=>$e]);
            return $this->json(['ok'=>false,'error'=>'research_execution_failed',
                'reason'=>get_class($e)],503);
        }
    }

    #[Route('/api/profile/direct-research/{id}', name: 'assistant_profile_direct_research_status', methods: ['GET'], requirements: ['id' => '[a-f0-9]{32}'])]
    public function directResearchStatus(string $id): JsonResponse {
        $file = dirname(__DIR__, 2).'/runtime/state/profile-direct/'.$id.'.result.json';
        if (!is_file($file)) return $this->json(['ok'=>false,'error'=>'job_not_found'],404);
        try {
            $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            return $this->json(is_array($data) ? $data : ['ok'=>false,'error'=>'invalid_job_result']);
        } catch (\Throwable $e) {
            return $this->json(['ok'=>false,'error'=>'job_result_unreadable'],503);
        }
    }

}
