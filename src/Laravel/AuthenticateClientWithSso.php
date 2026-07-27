<?php

declare(strict_types=1);

namespace LatchVector\Sso\Laravel;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LatchVector\Sso\Exception\TokenVerificationException;
use LatchVector\Sso\TokenVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a valid machine (client_credentials) token and attaches the
 * ClientPrincipal to the request.
 *
 * Registered as the `sso.client` middleware alias — the machine-to-machine
 * counterpart of `sso.auth`:
 *
 *   Route::post('/reports/sync', ...)->middleware('sso.client');
 *
 * It verifies with {@see TokenVerifier::verifyClient()}, so a user access
 * token is rejected here exactly as a machine token is rejected by `sso.auth`.
 * The client is available as `$request->attributes->get('sso_client')`.
 */
final class AuthenticateClientWithSso
{
    public function __construct(private readonly TokenVerifier $verifier)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $client = $this->verifier->verifyClientAuthorizationHeader(
                $request->header('Authorization'),
            );
        } catch (TokenVerificationException) {
            // Opaque on purpose — see AuthenticateWithSso.
            return new JsonResponse(
                ['error' => 'invalid_token'],
                401,
                ['WWW-Authenticate' => 'Bearer'],
            );
        }

        $request->attributes->set('sso_client', $client);

        // A machine token carries a tenant too, so tenant-aware models scope
        // themselves the same way for a backend job as for a user. Machine
        // callers are never bypass-exempt — bypass is a user-permission notion.
        app(\LatchVector\Sso\Tenancy\TenantContext::class)->set($client->tenantId, false);

        return $next($request);
    }
}
