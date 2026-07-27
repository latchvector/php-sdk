<?php

declare(strict_types=1);

namespace LatchVector\Sso\Laravel;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LatchVector\Sso\Principal;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires permissions. Must run after `sso.auth`.
 *
 *   Route::post('/invoices/{id}/approve', ...)
 *       ->middleware(['sso.auth', 'sso.can:invoice.approve']);
 *
 * Several codes are "all of them" by default; append `,any` for
 * "at least one":
 *
 *   ->middleware('sso.can:invoice.approve,invoice.admin,any');
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $principal = $request->attributes->get('sso_principal');

        if (!$principal instanceof Principal) {
            // A permission check that silently passes when the auth
            // middleware was forgotten is worse than no check at all, so
            // this is an exception rather than a 403 — it is a wiring bug
            // in your routes, not a user's fault.
            throw new RuntimeException(
                'sso.can used without sso.auth earlier in the middleware stack.',
            );
        }

        $mode = 'all';
        if ($permissions !== [] && in_array(end($permissions), ['any', 'all'], true)) {
            $mode = array_pop($permissions);
        }

        $ok = $mode === 'any'
            ? $principal->hasAny(...$permissions)
            : $principal->hasAll(...$permissions);

        if (!$ok) {
            // Does not distinguish "forbidden" from "does not exist" —
            // telling them apart would let anyone enumerate records
            // across tenants.
            return new JsonResponse(['error' => 'access_denied'], 403);
        }

        return $next($request);
    }
}
