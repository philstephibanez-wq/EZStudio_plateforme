<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Statut REST minimal LAB pour la supervision federée ; aucun acces aux jobs. */
final class ServiceStatusController
{
    private const VERSION_PATTERN = '/^\d+\.\d+(?:[a-z]+\d*)?$/D';

    public static function allowed(string $host, string $clientIp): bool
    {
        return in_array($host, ['127.0.0.1', 'localhost'], true)
            && in_array($clientIp, ['127.0.0.1', '::1'], true);
    }

    /** Contrat exact accepte par le collecteur WebSoftPingController (v4.2e). */
    public static function payload(string $version, string $timestampUtc): array
    {
        $valid = preg_match(self::VERSION_PATTERN, $version) === 1;

        return [
            'schema' => 'ezs.service-status.v1',
            'application' => 'ezstudio_lab',
            'instance' => 'lab',
            'version' => $valid ? $version : null,
            'state' => $valid ? 'OK' : 'KO',
            'health' => $valid ? 'OK' : 'ERROR',
            'activity' => 'UNKNOWN',
            'components' => [
                ['name' => 'symfony_http', 'health' => 'OK', 'evidence' => 'route_executed'],
                ['name' => 'version', 'health' => $valid ? 'OK' : 'ERROR', 'evidence' => $valid ? 'canonical_version_read' : 'canonical_version_unavailable'],
                ['name' => 'database', 'health' => 'UNKNOWN', 'evidence' => 'not_probed'],
                ['name' => 'workers', 'health' => 'UNKNOWN', 'evidence' => 'not_probed'],
                ['name' => 'scientific_pipeline', 'health' => 'UNKNOWN', 'evidence' => 'not_probed'],
            ],
            'captured_utc' => $timestampUtc,
        ];
    }

    #[Route('/api/v1/service-status', name: 'lab_service_status_v1', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store'];
        if (!self::allowed($request->getHost(), (string) $request->getClientIp())) {
            return new JsonResponse(['error' => 'forbidden'], 403, $headers);
        }

        $versionFile = dirname(__DIR__, 2).'/VERSION';
        $version = is_file($versionFile) && !is_link($versionFile)
            ? trim((string) @file_get_contents($versionFile)) : '';
        $data = self::payload($version, gmdate('Y-m-d\TH:i:s\Z'));

        return new JsonResponse($data, $data['state'] === 'OK' ? 200 : 503, $headers);
    }
}
