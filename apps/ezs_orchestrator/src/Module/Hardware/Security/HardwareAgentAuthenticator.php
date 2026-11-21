<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Hardware\Security;

use Shared\Hardware\AgentCredentials;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/** Symfony Firewall for one read-only route; reuse established per-agent credentials. */
final class HardwareAgentAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): ?bool
    {
        return $request->getPathInfo() === '/internal/hardware/inventory';
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $agent = (new AgentCredentials())->identify($request->headers->get('Authorization'));
        if (!in_array($agent, ['lab','assistant','ezscore'], true)) {
            throw new AuthenticationException('agent_authentication_failed');
        }
        return new SelfValidatingPassport(new UserBadge('agent:'.$agent,
            static fn (string $identifier): HardwareAgentIdentity => new HardwareAgentIdentity(substr($identifier, 6))));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error'=>'unauthorized'], 401,
            ['Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff']);
    }
}
