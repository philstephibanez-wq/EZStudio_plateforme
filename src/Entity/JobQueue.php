<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
class JobQueue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $source; // 'lab' ou 'assistant'

    #[ORM\Column(length: 20)]
    private string $status = 'pending'; // pending, running, completed, failed

    #[ORM\Column(type: 'json')]
    private array $payload = [];

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct(string $source, array $payload)
    {
        $this->source = $source;
        $this->payload = $payload;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSource(): string { return $this->source; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self {
        $this->status = $status;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }
    public function getPayload(): array { return $this->payload; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
