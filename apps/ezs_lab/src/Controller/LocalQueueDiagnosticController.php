<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use EZSLab\Service\Database;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Read-only, local DEV diagnostic: no queue claim, drain or worker command. */
final class LocalQueueDiagnosticController extends AbstractController
{
    #[Route('/internal/diagnostic/local-queue', name: 'lab_local_queue_diagnostic', methods: ['GET'])]
    public function index(Request $request, Database $database): JsonResponse
    {
        if ($this->getParameter('kernel.environment') !== 'dev'
            || !in_array($request->getHost(), ['localhost', '127.0.0.1'], true)
            || !in_array($request->getClientIp(), ['127.0.0.1', '::1'], true)) {
            return $this->json(['error' => 'diagnostic_not_available'], 404);
        }

        try {
            $statement = $database->pdo()->query(<<<'SQL'
SELECT id, kind, status, progress, scientific_run_id, song_id,
       dispatch_owner, created_at, updated_at
FROM analysis_jobs
WHERE dispatch_owner = 'lab'
  AND kind IN ('profile', 'assistant.profile.review')
  AND status IN ('queued', 'running', 'cancelling')
ORDER BY created_at ASC, id ASC
LIMIT 200
SQL);
            $jobs = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return $this->json([
                'schema' => 'ezstudio.local-queue-diagnostic.v1',
                'collection' => [
                    'source' => 'lab.analysis_jobs',
                    'collected_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'collection_error' => 'sqlite_query_failed',
                ],
                'data' => null,
            ], 503);
        }

        return $this->json([
            'schema' => 'ezstudio.local-queue-diagnostic.v1',
            'collection' => [
                'source' => 'lab.analysis_jobs',
                'collected_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'collection_error' => null,
                'limit' => 200,
            ],
            'data' => ['jobs' => $jobs],
        ]);
    }
}
