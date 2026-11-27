<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use EZSLab\Service\Database;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileChatGptController
{
    #[Route('/workbench/profile/expertise-chatgpt', name: 'profile_expertise_chatgpt', methods: ['POST'])]
    public function __invoke(Request $request, Database $db): Response
    {
        $headers = ['Cache-Control' => 'no-store, private'];
        if (!in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            || $request->headers->get('Origin') !== $request->getSchemeAndHttpHost()
            || $request->headers->get('X-EZS-Expertise') !== 'chatgpt') {
            return new JsonResponse(['error' => 'Expertise ChatGPT réservée au LAB local.'], 403, $headers);
        }
        $song = filter_var($request->request->get('song'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $run = filter_var($request->request->get('run'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$song || !$run) {
            return new JsonResponse(['error' => 'Sélectionne une analyse PROFILE terminée.'], 422, $headers);
        }
        $python = getenv('EZS_CHATGPT_PYTHON') ?: ($_SERVER['EZS_CHATGPT_PYTHON'] ?? $_ENV['EZS_CHATGPT_PYTHON'] ?? '');
        if (!$python || !is_file($python)) {
            return new JsonResponse(['error' => 'Configure EZS_CHATGPT_PYTHON avec un Python équipé de PyJWT[crypto].'], 503, $headers);
        }
        $root = dirname(__DIR__, 2);
        $process = new Process(
            [$python, '-X', 'utf8', '-B', $root.'/scripts/profile-chatgpt.py', $db->path(), (string) $song, (string) $run],
            $root,
        );
        $process->setTimeout(590);

        return new StreamedResponse(static function () use ($process): void {
            $emit = static function (array $event): void {
                echo json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            $allowed = ['export', 'oauth', 'search', 'compare'];
            $stderr = '';
            try {
                $process->run(static function (string $type, string $chunk) use (&$stderr, $allowed, $emit): void {
                    if ($type !== Process::ERR) {
                        return;
                    }
                    $stderr .= $chunk;
                    while (($end = strpos($stderr, "\n")) !== false) {
                        $line = trim(substr($stderr, 0, $end));
                        $stderr = substr($stderr, $end + 1);
                        if (str_starts_with($line, 'EZS_REFERENCE ')) {
                            $reference = json_decode(substr($line, 14), true, 32, JSON_THROW_ON_ERROR);
                            if (is_array($reference) && isset($reference['run'], $reference['references'])) {
                                $emit(['reference' => $reference]);
                            }
                        } elseif (str_starts_with($line, 'EZS_STAGE ')) {
                            $stage = substr($line, 10);
                            if (in_array($stage, $allowed, true)) {
                                $emit(['stage' => $stage]);
                            }
                        }
                    }
                    if (strlen($stderr) > 262144) {
                        $stderr = '';
                    }
                });
                $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($result) || (!$process->isSuccessful() && !isset($result['error']))) {
                    throw new \RuntimeException();
                }
                $emit($result);
            } catch (\Throwable) {
                $emit(['error' => 'Le client ChatGPT local est indisponible ou a d?pass? le d?lai.']);
            }
        }, 200, $headers + [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
