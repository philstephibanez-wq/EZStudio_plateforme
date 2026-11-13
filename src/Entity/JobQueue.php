<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'job_queue')]
class JobQueue
{
    public const STATUS_WAITING = 'waiting';
    public const STATUS_PROGRESSING = 'progressing';
    public const STATUS_ENDED = 'ended';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $source; // 'lab' ou 'assistant'

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_WAITING; // waiting, progressing, ended

    #[ORM\Column(type: 'integer')]
    private int $progress = 0;

    #[ORM\Column(name: 'has_error', type: 'boolean')]
    private bool $hasError = false;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(type: 'json')]
    private array $payload = [];

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct(string $source, array $payload)
    {
        $this->source = $source;
        $this->payload = $payload;
        $this->status = self::STATUS_WAITING;
        $this->progress = 0;
        $this->hasError = false;
        $this->attempts = 0;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSource(): string { return $this->source; }
    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getProgress(): int { return $this->progress; }

    public function setProgress(int $progress): self
    {
        $this->progress = max(0, min(100, $progress));
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function hasError(): bool { return $this->hasError; }

    public function setHasError(bool $hasError): self
    {
        $this->hasError = $hasError;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getErrorMessage(): ?string { return $this->errorMessage; }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getAttempts(): int { return $this->attempts; }

    public function incrementAttempts(): self
    {
        ++$this->attempts;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getPayload(): array { return $this->payload; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?DateTimeImmutable { return $this->updatedAt; }
}