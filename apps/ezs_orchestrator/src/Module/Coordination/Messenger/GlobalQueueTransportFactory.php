<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Messenger;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/** Explicit DSN; never activates a second queue, dispatcher or consumer automatically. */
final class GlobalQueueTransportFactory implements TransportFactoryInterface
{
    public function supports(string $dsn, array $options): bool
    {
        return $dsn === 'ezs-global://local';
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        if (!$this->supports($dsn, $options)) throw new \InvalidArgumentException('unsupported_global_transport');
        return new GlobalQueueReceiver();
    }
}
