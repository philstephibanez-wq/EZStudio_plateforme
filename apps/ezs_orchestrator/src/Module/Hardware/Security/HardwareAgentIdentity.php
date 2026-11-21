<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Hardware\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/** An application identity, never an end-user identity. */
final class HardwareAgentIdentity implements UserInterface
{
    public function __construct(private readonly string $agentId) {}

    public function getUserIdentifier(): string { return 'agent:'.$this->agentId; }
    public function getRoles(): array { return ['ROLE_HARDWARE_READ']; }
    public function eraseCredentials(): void {}
}
