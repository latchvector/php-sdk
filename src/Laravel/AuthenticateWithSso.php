<?php

declare(strict_types=1);

namespace LatchVector\Sso\Laravel;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LatchVector\Sso\Exception\TokenVerificationException;
use LatchVector\Sso\Tenancy\TenantContext;
use LatchVector\Sso\TokenVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a valid access token and attaches the principal to the request.
 *
 * Registered as the `sso.auth` middleware alias.
 *
 *   Route::get('/invoices', ...)->middleware('sso.auth');
 *
 * The principal is available as `$request->principal()` via the
 * InteractsWithSso trait, or `$request->attributes->get('sso_principal')`.
 */
final class AuthenticateWithSso
{
    public function __construct(private readonly TokenVerifier $verifier)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $principal = $this->verifier->verifyAuthorizationHeader(
                $request->header('Authorization'),
            );
        } catch (TokenVerificationException) {
            // Deliberately opaque. Telling the caller *why* verification
            // failed helps an attacker tune a forged token far more than
            // it helps a legitimate integrator, who has the token in hand.
            return new JsonResponse(
                ['error' => 'invalid_token'],
                401,
                ['WWW-Authenticate' => 'Bearer'],
            );
        }

        $request->attributes->set('sso_principal', $principal);

        // Bind the request to the caller's tenant AND their reach within it, so
        // tenant-aware models scope themselves — subtree models down to exactly
        // the org paths the token grants. A caller holding a configured bypass
        // permission (a platform operator) is left unconstrained.
        $bypass = (array) config('latchvector-sso.tenant.bypass_permissions', []);
        app(TenantContext::class)->set(
            $principal->tenantId,
            array_intersect($bypass, $principal->permissions) !== [],
            $principal->orgId,
            $principal->orgPath,
            $principal->scopeSubtree,
            $principal->scopeSelf,
        );

        return $next($request);
    }
}
