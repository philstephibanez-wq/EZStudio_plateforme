<?php

declare(strict_types=1);

namespace EZSLab\Service;

use App\Service\OrchestratorQueueClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'lab:profile:expertise-job', description: 'Exécuter une expertise ChatGPT admise par la FIFO')]
final class ProfileExpertiseJob extends Command
{
    public const KIND = 'profile_chatgpt';

    public function __construct(private readonly Database $db, private readonly OrchestratorQueueClient $queue)
    {
        parent::__construct();
    }

    public function enqueue(int $song, int $run, int $revision): array
    {
        if ($this->result($song, $run, $revision) !== null) {
            return ['queue_id' => null, 'status' => 'done'];
        }
        return $this->queue->dispatch('lab', [
            'job' => self::KIND, 'category' => 'Expertise',
            'song_id' => $song, 'run_id' => $run, 'prompt_revision_id' => $revision,
        ]);
    }

    public function candidates(?int $song = null, ?int $run = null): array
    {
        $stmt = $this->db->pdo()->prepare(<<<'SQL'
            SELECT s.id AS song, s.title, MAX(r.id) AS run, p.revision_id AS revision
            FROM songs s
            JOIN songs origin ON origin.audio_sha256 = s.audio_sha256
            JOIN scientific_runs r ON r.song_id = origin.id AND r.item = 'profile' AND r.state = 'done'
            JOIN profile_expertise_prompt_active p ON p.id = 1
            WHERE trim(s.title) <> '' AND trim(s.artist) <> ''
              AND ((? IS NULL AND s.id = (SELECT MAX(id) FROM songs WHERE audio_sha256 = s.audio_sha256)) OR s.id = ?)
              AND (? IS NULL OR r.id = ?)
            GROUP BY s.id, p.revision_id
            ORDER BY s.title COLLATE NOCASE, s.artist COLLATE NOCASE, s.id
            SQL);
        $stmt->execute([$song, $song, $run, $run]);
        return $stmt->fetchAll();
    }

    public function status(int $id, int $song, int $run, int $revision): array
    {
        $proof = $this->queue->executionProof($id);
        if (($proof['source'] ?? null) !== 'lab' || ($proof['job'] ?? null) !== self::KIND
            || (int) ($proof['song_id'] ?? 0) !== $song || (int) ($proof['run_id'] ?? 0) !== $run
            || (int) ($proof['prompt_revision_id'] ?? 0) !== $revision) {
            throw new \DomainException('Job d’expertise incompatible.');
        }
        return $proof;
    }

    public function result(int $song, int $run, int $revision): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT jsonl FROM profile_chatgpt_results WHERE song_id=? AND source_run_id=? AND prompt_revision_id=?'
        );
        $stmt->execute([$song, $run, $revision]);
        $jsonl = $stmt->fetchColumn();
        return $jsonl === false ? null : ['run' => $run, 'prompt_revision' => $revision, 'jsonl' => $jsonl];
    }

    protected function configure(): void
    {
        foreach (['queue', 'song', 'run', 'revision'] as $argument) {
            $this->addArgument($argument, InputArgument::REQUIRED);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [$id, $song, $run, $revision] = array_map(
            static fn (string $key): int => (int) $input->getArgument($key),
            ['queue', 'song', 'run', 'revision'],
        );
        if (min($id, $song, $run, $revision) < 1 || (int) getenv('ORCHESTRATOR_JOB_ID') !== $id
            || $this->status($id, $song, $run, $revision)['status'] !== 'progressing') {
            return Command::FAILURE;
        }
        if ($this->result($song, $run, $revision) !== null) {
            return Command::SUCCESS;
        }

        $python = getenv('EZS_CHATGPT_PYTHON') ?: ($_SERVER['EZS_CHATGPT_PYTHON'] ?? $_ENV['EZS_CHATGPT_PYTHON'] ?? '');
        if (!$python || !is_file($python)) {
            $output->writeln('EZS_CHATGPT_PYTHON_MISSING');
            return Command::FAILURE;
        }
        $root = dirname(__DIR__, 2);
        $process = new Process([$python, '-X', 'utf8', '-B', $root.'/scripts/profile-chatgpt.py',
            $this->db->path(), (string) $song, (string) $run, (string) $revision], $root);
        $process->setTimeout(590);
        $timedOut = false;
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $error) {
            $timedOut = true;
        }
        $data = json_decode($process->getOutput(), true);
        if ($timedOut || !$process->isSuccessful() || ($data['run'] ?? null) !== $run
            || ($data['prompt_revision'] ?? null) !== $revision || !is_string($data['jsonl'] ?? null)
            || trim($data['jsonl']) === '') {
            // EZS_PROFILE_FIFO_R2_DIAG: only exact finite diagnostic markers cross the boundary.
            $stage = 'unknown';
            $code = 'python_exit';
            $stages = ['unknown', 'export', 'session_lock', 'registration', 'oauth', 'models', 'model_select', 'search', 'result'];
            $codes = ['run_unavailable', 'revision_mismatch', 'template_invalid', 'lock_busy', 'model_unavailable', 'event_error', 'event_failed', 'event_incomplete', 'terminal_not_completed', 'stream_incomplete', 'empty_text', 'rate_limit_exceeded', 'insufficient_quota', 'usage_limit_reached', 'quota_exceeded', 'authentication_error', 'invalid_api_key', 'invalid_token', 'token_expired', 'permission_denied', 'access_denied', 'forbidden', 'model_not_found', 'server_error', 'internal_error', 'invalid_request_error', 'context_length_exceeded', 'content_filter', 'max_output_tokens', 'json_decode', 'timeout', 'export_subprocess', 'os_error', 'key_error', 'value_error', 'oauth_failure', 'unexpected', 'php_timeout', 'python_exit', 'invalid_stdout', 'invalid_run', 'invalid_revision', 'invalid_jsonl'];
            foreach (preg_split('/\r?\n/', $process->getErrorOutput()) as $line) {
                if (preg_match('/\AEZS_STAGE ([a-z_]+)\z/D', $line, $m) && in_array($m[1], $stages, true)) {
                    $stage = $m[1];
                }
                if (preg_match('/\AEZS_DIAG ([a-z_]+) ([a-z0-9_]+)\z/D', $line, $m)
                    && in_array($m[1], $stages, true)
                    && (in_array($m[2], $codes, true) || preg_match('/\Ahttp_[45][0-9]{2}\z/D', $m[2]))) {
                    $stage = $m[1];
                    $code = $m[2];
                }
            }
            if ($timedOut) {
                $code = 'php_timeout';
            } elseif ($process->isSuccessful()) {
                $code = !is_array($data) ? 'invalid_stdout'
                    : (($data['run'] ?? null) !== $run ? 'invalid_run'
                    : (($data['prompt_revision'] ?? null) !== $revision ? 'invalid_revision' : 'invalid_jsonl'));
            }
            $output->writeln(sprintf('EZS_PROFILE_DIAG job=%d song=%d run=%d revision=%d exit=%d stage=%s code=%s',
                $id, $song, $run, $revision, (int) ($process->getExitCode() ?? -1), $stage, $code));
            $output->writeln('QUEUE_ERROR ProfileExpertise: profile_'.$stage.'_'.$code);
            $output->writeln('EZS_CHATGPT_EXPERTISE_FAILED');
            return Command::FAILURE;
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO profile_chatgpt_results (song_id, source_run_id, prompt_revision_id, jsonl) VALUES (?, ?, ?, ?)
             ON CONFLICT(song_id, source_run_id, prompt_revision_id) DO NOTHING'
        );
        $stmt->execute([$song, $run, $revision, $data['jsonl']]);
        return Command::SUCCESS;
    }
}
