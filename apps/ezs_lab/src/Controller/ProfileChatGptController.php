<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use EZSLab\Service\ProfileExpertiseJob;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileChatGptController
{
    #[Route('/workbench/profile/expertise-chatgpt', name: 'profile_expertise_chatgpt', methods: ['POST'])]
    public function __invoke(Request $request, ProfileExpertiseJob $jobs): JsonResponse
    {
        if (!in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            || $request->headers->get('Origin') !== $request->getSchemeAndHttpHost()
            || $request->headers->get('X-EZS-Expertise') !== 'chatgpt') {
            return $this->reply(['error' => 'Expertise ChatGPT réservée au LAB local.'], 403);
        }
        $all = $request->request->get('all') === '1';
        $song = $request->request->getInt('song');
        $run = $request->request->getInt('run');
        $revision = $request->request->getInt('revision');
        $queue = $request->request->getInt('queue');
        $read = $request->request->get('read') === '1';

        try {
            if (!$all && min($song, $run) < 1) {
                return $this->reply(['error' => 'Sélectionne une analyse PROFILE terminée.'], 422);
            }
            if ($queue > 0) {
                $proof = $jobs->status($queue, $song, $run, $revision);
                return $this->reply(['status' => $proof['status'],
                    'result' => $jobs->result($song, $run, $revision)]);
            }
            $rows = $jobs->candidates($all ? null : $song, $all ? null : $run);
            if (!$all) {
                if (!$rows) {
                    return $this->reply(['error' => 'Analyse PROFILE indisponible pour l’expertise.'], 422);
                }
                $revision = (int) $rows[0]['revision'];
                if ($result = $jobs->result($song, $run, $revision)) {
                    return $this->reply($result);
                }
                if ($read) {
                    return $this->reply([]);
                }
                return $this->reply($jobs->enqueue($song, $run, $revision) + ['revision' => $revision]);
            }

            $queued = $done = 0;
            try {
                foreach ($rows as $row) {
                    $job = $jobs->enqueue((int) $row['song'], (int) $row['run'], (int) $row['revision']);
                    if ($job['queue_id'] === null) {
                        ++$done;
                    } else {
                        ++$queued;
                    }
                }
            } catch (\Throwable) {
                return $this->reply(['error' => 'Admission interrompue. Relance pour reprendre.',
                    'queued' => $queued, 'done' => $done], 503);
            }
            return $this->reply(['queued' => $queued, 'done' => $done]);
        } catch (\Throwable) {
            return $this->reply(['error' => 'FIFO Orchestrator ou expertise indisponible.'], 503);
        }
    }

    private function reply(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private']);
    }
}
