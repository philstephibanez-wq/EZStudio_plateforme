<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final class QueueActionCsrfManager
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    public function generate(Request $request, string $intention): string
    {
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $nonce = (string) $session->get('_ezs_queue_csrf_seed', '');
        if ($nonce === '') {
            $nonce = bin2hex(random_bytes(16));
            $session->set('_ezs_queue_csrf_seed', $nonce);
        }
        return hash_hmac('sha256', $intention . '|' . $nonce, $this->secret);
    }

    public function isValid(Request $request, string $intention, string $providedToken): bool
    {
        if ($providedToken === '' || !$request->hasSession()) {
            return false;
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $nonce = (string) $session->get('_ezs_queue_csrf_seed', '');
        if ($nonce === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $intention . '|' . $nonce, $this->secret);
        return hash_equals($expected, $providedToken);
    }
}
