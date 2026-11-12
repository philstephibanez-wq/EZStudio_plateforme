<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Messenger;

use Ezs\Web\Module\Coordination\GlobalQueueLocation;
use PDO;
use Symfony\Component\Process\Process;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/** Single-reader, receiver-only Messenger transport over the canonical queue.
 * Never imports or replays pre-cutover local jobs.
 */
final class GlobalQueueReceiver implements TransportInterface
{
    /** @var resource */
    private $lock;
    private PDO $db;
    private string $queuePath;
    private int $deferUntil = 0;
    private int $nextAdmissionPoll = 0;

    public function __construct(?string $queuePath = null)
    {
        $path = $queuePath ?? GlobalQueueLocation::path();
        $this->queuePath = $path;
        if (!is_file($path)) {
            throw new \RuntimeException('canonical_queue_missing');
        }
        $lockPath = dirname($path) . DIRECTORY_SEPARATOR . 'global-messenger-consumer.lock';
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new \RuntimeException('global_consumer_already_running');
        }
        $this->lock = $lock;
        $uri = 'file:' . str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], str_replace('\\', '/', $path)) . '?mode=ro';
        $this->db = new PDO('sqlite:' . $uri, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
        ]);
        $this->db->exec('PRAGMA query_only=ON');
    }

    public function get(): iterable
    {
        if (time() < $this->deferUntil) return [];
        // Explicit incident recovery must never publish a new LAB admission.
        // Normal Messenger operation retains its canonical outbox publisher.
        if (getenv('EZS_QUEUE_RECOVERY_ONLY') !== '9,10') {
            $this->publishLabPending();
        }
        // Never bypass an active/exited-but-unreconciled execution.
        $busy = $this->db->query("SELECT 1 FROM global_queue_jobs WHERE state='running' LIMIT 1");
        if ($busy->fetchColumn() !== false) return [];
        $row = $this->db->query("SELECT id,producer,external_id,kind,claim_generation FROM global_queue_jobs WHERE state='queued' ORDER BY created_at,id LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return [];
        // No fallback to a different job: FIFO stays strict even for unimplemented producers.
        if ($row['producer'] !== 'lab' || $row['kind'] !== 'profile'
            || preg_match('/^analysis_job:[1-9][0-9]*$/D', (string)$row['external_id']) !== 1) {
            throw new \RuntimeException('fifo_head_executor_not_implemented');
        }
        $reference = new QueueJobReference((int)$row['id'], (string)$row['producer'], (int)$row['claim_generation']);
        return [new Envelope($reference, [new TransportMessageIdStamp((string)$row['id'])])];
    }

    /** Consume LAB's durable outbox without importing historical local-only jobs. */
    private function publishLabPending(): void
    {
        if (time() < $this->nextAdmissionPoll) return;
        $this->nextAdmissionPoll = time() + 10;
        $lab = 'H:/EZStudio_lab';
        if (!is_file($lab.'/bin/console')) return;
        $process = new Process([PHP_BINARY, 'bin/console', 'lab:queue:publish-pending', '--apply'], $lab);
        $process->setTimeout(15);
        $process->disableOutput();
        try {
            $process->run();
        } catch (\Throwable) {
            // Admission is retried on the next poll; no existing global claim is changed.
        }
    }

    public function ack(Envelope $envelope): void
    {
        // The LAB bridge persists/finalizes through authenticated endpoints.
        // Messenger ack must not mutate job state or acknowledge before persistence.
    }

    public function reject(Envelope $envelope): void
    {
        // Bounded delay avoids an unproductive fast loop after a failure.
        // The global job remains authoritative and requires state reconciliation.
        $this->deferUntil = time() + 15;
    }

    public function send(Envelope $envelope): Envelope
    {
        throw new \LogicException('global_queue_receiver_is_read_only');
    }

    public function __destruct()
    {
        if (isset($this->lock) && is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
    }
}
