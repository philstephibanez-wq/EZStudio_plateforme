<?php
declare(strict_types=1);

namespace EZSAssistant\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MainBackofficeController extends AbstractController
{
    #[Route('/', name: 'assistant_main_home', methods: ['GET'])]
    #[Route('/backoffice', name: 'assistant_main_backoffice', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('backoffice/main.html.twig', [
            'version' => trim((string) file_get_contents(dirname(__DIR__, 2).'/VERSION')),
        ]);
    }

    private function readJson(string $url): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 6,
            'ignore_errors' => false,
            'header' => "Accept: application/json\r\nUser-Agent: EZStudioLabAssistant-MAIN/1.6 (independent music review)\r\n",
        ]]);
        $response = @file_get_contents($url, false, $context, 0, 1000000);
        if (!is_string($response)) {
            return null;
        }
        $parsed = json_decode($response, true);
        return is_array($parsed) ? $parsed : null;
    }

    #[Route('/api/backoffice/inference', name: 'assistant_main_inference', methods: ['GET'])]
    public function inference(): JsonResponse
    {
        // Local native llama.cpp Vulkan endpoint. Monitoring must be read-only.
        $health = $this->readJson('http://127.0.0.1:8720/health');
        $ready = ($health['status'] ?? null) === 'ok';
        $models = $ready ? $this->readJson('http://127.0.0.1:8720/v1/models') : null;
        $model = $models['data'][0]['id'] ?? null;
        return $this->json([
            'ok' => $ready,
            'state' => $ready ? 'READY' : 'UNAVAILABLE',
            'engine' => 'llama.cpp Vulkan NVIDIA MAIN',
            'model' => is_string($model) ? $model : null,
            'musical_verdict' => null,
        ], $ready ? 200 : 503);
    }

    #[Route('/api/backoffice/recordings', name: 'assistant_main_recordings', methods: ['GET'])]
    public function recordings(Request $request): JsonResponse
    {
        $title = trim((string) $request->query->get('title', ''));
        $artist = trim((string) $request->query->get('artist', ''));
        if ($title === '' || $artist === '' || mb_strlen($title) > 120 || mb_strlen($artist) > 120) {
            return $this->json(['ok' => false, 'error' => 'title_artist_required'], 422);
        }
        $query = 'recording:"'.str_replace('"', '', $title).'" AND artist:"'.str_replace('"', '', $artist).'"';
        $url = 'https://musicbrainz.org/ws/2/recording/?'.http_build_query([
            'query' => $query, 'fmt' => 'json', 'limit' => 10,
        ], '', '&', PHP_QUERY_RFC3986);
        $payload = $this->readJson($url);
        if ($payload === null) {
            return $this->json(['ok' => false, 'error' => 'musicbrainz_unavailable'], 503);
        }
        $candidates = [];
        foreach (($payload['recordings'] ?? []) as $recording) {
            if (!is_array($recording) || !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', (string) ($recording['id'] ?? ''))) {
                continue;
            }
            $names = [];
            foreach (($recording['artist-credit'] ?? []) as $credit) {
                if (is_array($credit) && is_string($credit['name'] ?? null)) {
                    $names[] = $credit['name'];
                }
            }
            $foundTitle = (string) ($recording['title'] ?? '');
            $foundArtist = trim(implode(' ', $names));
            if (mb_strtolower($foundTitle) !== mb_strtolower($title) || mb_strtolower($foundArtist) !== mb_strtolower($artist)) {
                continue;
            }
            $candidates[] = [
                'title' => $foundTitle,
                'artist' => $foundArtist,
                'mbid' => $recording['id'],
                'source_url' => 'https://musicbrainz.org/recording/'.$recording['id'],
                'identity_verified' => false,
                'musical_verdict' => null,
            ];
        }
        return $this->json([
            'ok' => true,
            'provider' => 'MusicBrainz',
            'candidates' => $candidates,
            'recording_verified' => false,
            'musical_verdict' => null,
        ]);
    }
}
