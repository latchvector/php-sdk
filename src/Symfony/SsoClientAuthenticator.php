<?php

declare(strict_types=1);

namespace LatchVector\Sso\Symfony;

use LatchVector\Sso\Exception\TokenVerificationException;
use LatchVector\Sso\TokenVerifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Symfony security authenticator for Latch Vector machine (client_credentials)
 * tokens — the counterpart of {@see SsoAuthenticator}.
 *
 * Put it on a firewall of its own, so machine and user callers are separated:
 *
 *     # security.yaml
 *     firewalls:
 *         api_machine:
 *             pattern: ^/api/machine
 *             stateless: true
 *             custom_authenticators:
 *                 - LatchVector\Sso\Symfony\SsoClientAuthenticator
 *
 * It verifies with {@see TokenVerifier::verifyClient()}, so a user access token
 * is rejected here just as a machine token is rejected by SsoAuthenticator. The
 * authenticated user is an {@see SsoClientUser}, and the token's scopes become
 * roles verbatim — so `#[IsGranted('reports.write')]` works with them.
 */
final class SsoClientAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly TokenVerifier $verifier)
    {
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        try {
            $client = $this->verifier->verifyClientAuthorizationHeader(
                $request->headers->get('Authorization'),
            );
        } catch (TokenVerificationException $e) {
            throw new CustomUserMessageAuthenticationException('invalid_token', previous: $e);
        }

        $request->attributes->set('sso_client', $client);

        return new SelfValidatingPassport(
            new UserBadge($client->clientId, static fn (): SsoClientUser => new SsoClientUser($client)),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Never echo the exception message — see SsoAuthenticator.
        return new JsonResponse(
            ['error' => 'invalid_token'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
