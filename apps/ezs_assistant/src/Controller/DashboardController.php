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
        return $this->json([
            'ok'=>false, 'state'=>'DISABLED', 'engine'=>null, 'engine_version'=>null,
            'model'=>null, 'endpoint'=>null, 'health'=>null, 'generation_verified'=>false,
        ]);
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
}
