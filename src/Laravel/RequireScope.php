<?php

declare(strict_types=1);

namespace LatchVector\Sso\Laravel;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LatchVector\Sso\ClientPrincipal;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires scopes on a machine token. Must run after `sso.client`.
 *
 *   Route::post('/reports/sync', ...)
 *       ->middleware(['sso.client', 'sso.scope:reports.write']);
 *
 * Several scopes are "all of them" by default; append `,any` for
 * "at least one":
 *
 *   ->middleware('sso.scope:reports.read,reports.write,any');
 */
final class RequireScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $client = $request->attributes->get('sso_client');

        if (!$client instanceof ClientPrincipal) {
            // A scope check that silently passes when sso.client was
            // forgotten is worse than no check — this is a wiring bug in
            // your routes, not the caller's fault.
            throw new RuntimeException(
                'sso.scope used without sso.client earlier in the middleware stack.',
            );
        }

        $mode = 'all';
        if ($scopes !== [] && in_array(end($scopes), ['any', 'all'], true)) {
            $mode = array_pop($scopes);
        }

        if ($mode === 'any') {
            $ok = $client->hasAnyScope(...$scopes);
        } else {
            $ok = true;
            foreach ($scopes as $scope) {
                if (!$client->hasScope($scope)) {
                    $ok = false;
                    break;
                }
            }
        }

        if (!$ok) {
            return new JsonResponse(['error' => 'access_denied'], 403);
        }

        return $next($request);
    }
}
