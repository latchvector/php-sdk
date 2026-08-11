<?php

declare(strict_types=1);

namespace LatchVector\Sso\Symfony;

use LatchVector\Sso\Exception\TokenVerificationException;
use LatchVector\Sso\Principal;
use LatchVector\Sso\Tenancy\TenantContext;
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
 * Symfony security authenticator for Latch Vector access tokens.
 *
 * services.yaml:
 *
 *     LatchVector\Sso\TokenVerifier:
 *         arguments:
 *             $issuer: '%env(SSO_ISSUER)%'
 *             $audience: '%env(SSO_AUDIENCE)%'
 *             $cache: '@cache.app'
 *
 *     LatchVector\Sso\Symfony\SsoAuthenticator: ~
 *
 * security.yaml:
 *
 *     firewalls:
 *         api:
 *             pattern: ^/api
 *             stateless: true
 *             custom_authenticators:
 *                 - LatchVector\Sso\Symfony\SsoAuthenticator
 *
 * The authenticated user is an SsoUser carrying the Principal, and the
 * token's permissions become roles verbatim — so `#[IsGranted('invoice.approve')]`
 * works with the codes your application already defined.
 */
final class SsoAuthenticator extends AbstractAuthenticator
{
    /**
     * @param TenantContext|null $tenantContext populated from the verified token
     *        so the Doctrine tenant filter (and Laravel scope) know who is asking.
     *        Null when tenancy isn't used.
     * @param string|null $bypassPermission a permission code whose holder is left
     *        unconstrained by tenant scoping (e.g. a platform operator). Null = none.
     */
    public function __construct(
        private readonly TokenVerifier $verifier,
        private readonly ?TenantContext $tenantContext = null,
        private readonly ?string $bypassPermission = null,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        try {
            $principal = $this->verifier->verifyAuthorizationHeader(
                $request->headers->get('Authorization'),
            );
        } catch (TokenVerificationException $e) {
            // Deliberately opaque to the client — see onAuthenticationFailure.
            throw new CustomUserMessageAuthenticationException('invalid_token', previous: $e);
        }

        $request->attributes->set('sso_principal', $principal);

        // Publish the tenant + the caller's reach to the shared context, so
        // tenant-aware queries (Doctrine filter / Laravel scope) are confined to
        // this caller for the rest of the request. A holder of the configured
        // bypass permission is left unconstrained.
        $this->tenantContext?->fromPrincipal(
            $principal,
            $this->bypassPermission !== null ? [$this->bypassPermission] : [],
        );

        // SelfValidatingPassport: the signature already proved who this is,
        // so there is no second credential to check and no user provider
        // to hit. Loading a local user row here would add a query per
        // request to re-establish something the token already asserts.
        return new SelfValidatingPassport(
            new UserBadge((string) $principal->uid, static fn (): SsoUser => new SsoUser($principal)),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Never echo the exception message: it would tell an attacker
        // which of signature, issuer, audience or expiry failed, which
        // helps them tune a forged token and helps nobody else.
        return new JsonResponse(
            ['error' => 'invalid_token'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
