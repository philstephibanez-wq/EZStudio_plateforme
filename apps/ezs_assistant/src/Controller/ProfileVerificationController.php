<?php

declare(strict_types=1);

namespace EZSAssistant\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

final class ProfileVerificationController extends AbstractController
{
    public function __construct(private readonly LoggerInterface $logger) {}
    #[Route('/api/profile/verify', name: 'assistant_profile_verify_push', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $token = (string) $request->headers->get('X-EZScore-Analysis-Token', '');
        if ($token === '') {
            return $this->json(['error' => 'unauthorized'], 401);
        }
        try {
            $data = $request->toArray();
            if (array_key_exists('_auth_token', $data)) {
                return $this->json(['error' => 'reserved_field'], 422);
            }
            if (($data['expertise_type'] ?? null) !== 'profile') {
                return $this->json(['error' => 'expertise_type_not_implemented'], 422);
            }
            $data['_auth_token'] = $token;
            $root = dirname(__DIR__, 2);
            $python = $_ENV['EZSTUDIOLAB_ASSISTANT_PYTHON']
                ?? $_SERVER['EZSTUDIOLAB_ASSISTANT_PYTHON'] ?? 'python';
            $process = new Process([$python, $root.'/runtime/profile_verify_push.py'], $root);
            $process->setInput(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $process->setTimeout(900);
            $process->run();
            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());
            if ($stdout === '') {
                $this->logger->error("PROFILE verify returned empty output", [
                    "exit_code"=>$process->getExitCode(), "stderr_type"=>$stderr !== '' ? "present" : "empty",
                    "run_id"=>$data["analysis_id"] ?? null]);
                return $this->json(["error"=>"verification_empty_output","exit_code"=>$process->getExitCode()], 503);
            }
            try {
                $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $jsonError) {
                $this->logger->error("PROFILE verify returned malformed JSON", [
                    "exception_type"=>get_class($jsonError), "stdout_bytes"=>strlen($stdout),
                    "exit_code"=>$process->getExitCode()]);
                return $this->json(["error"=>"verification_invalid_json"],503);
            }
            if (!$process->isSuccessful()) {
                $error = is_array($result) ? ($result['error'] ?? 'verification_failed') : 'verification_failed';
                $this->logger->error('PROFILE verification subprocess failed', [
                    'error'=>$error,
                    'python_error_type'=>is_array($result) ? ($result['type'] ?? null) : null,
                    'exit_code'=>$process->getExitCode(),
                    'stderr_present'=>$stderr !== '',
                ]);
                return $this->json(['error'=>$error,
                    'reason'=>is_array($result) ? ($result['type'] ?? $result['reason'] ?? null) : null],
                    $error === 'unauthorized' ? 401 : 422);
            }
            return $this->json($result);
        } catch (\Throwable $e) {
            $reason = $e instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException
                ? "process_timeout" : get_class($e);
            $this->logger->error("PROFILE verification transport failure", [
                "exception"=>$e, "reason"=>$reason]);
            return $this->json(['error' => 'verification_processing_failed', 'reason' => $reason], 503);
        }
    }
}
