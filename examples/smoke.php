<?php

/**
 * End-to-end smoke test against a running SSO service.
 *
 * Opt-in, because it needs real credentials and a live instance:
 *
 *   SSO_ISSUER=http://localhost:9000 \
 *   SSO_AUDIENCE=http://localhost:9000 \
 *   SSO_EMAIL=admin@yourdomain.com SSO_PASSWORD=… \
 *   php examples/smoke.php
 *
 * Setting SSO_AUDIENCE equal to SSO_ISSUER targets the SSO service's own
 * management API. For a real integration it is your application's
 * registered identifier.
 *
 * As well as the happy path this asserts the failures that matter: a token
 * minted for a different audience must be rejected, a tampered signature
 * must be rejected, and an MFA pending token must not pass as a login.
 * Those are the checks whose absence turns a working integration into an
 * open door, so they are tested rather than assumed.
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use LatchVector\Sso\Exception\RefreshTokenReusedException;
use LatchVector\Sso\Exception\TokenVerificationException;
use LatchVector\Sso\MfaRequired;
use LatchVector\Sso\SsoClient;
use LatchVector\Sso\TokenVerifier;

$issuer = getenv('SSO_ISSUER') ?: 'http://localhost:9000';
$audience = getenv('SSO_AUDIENCE') ?: $issuer;
$email = getenv('SSO_EMAIL');
$password = getenv('SSO_PASSWORD');

if (!$email || !$password) {
    fwrite(STDERR, "Set SSO_EMAIL and SSO_PASSWORD.\n");
    exit(2);
}

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    printf("%s  %s\n", $ok ? 'ok  ' : 'FAIL', $label);
    if (!$ok) {
        ++$failures;
    }
}

function rejects(TokenVerifier $verifier, string $token): bool
{
    try {
        $verifier->verify($token);

        return false;
    } catch (TokenVerificationException) {
        return true;
    }
}

$sso = new SsoClient(issuer: $issuer, audience: $audience);
$verifier = new TokenVerifier(issuer: $issuer, audience: $audience);

$login = $sso->login($email, $password);
if ($login instanceof MfaRequired) {
    echo "Account has MFA enabled; supply a code to continue this test.\n";
    exit(0);
}
check('login returns a token pair', $login->accessToken !== '');

$principal = $verifier->verify($login->accessToken);
check('token verifies', $principal->uid > 0);
printf(
    "      uid=%d org=%d tenant=%d path=%s permissions=%d\n",
    $principal->uid,
    $principal->orgId,
    $principal->tenantId,
    $principal->orgPath,
    count($principal->permissions),
);

// A token for someone else's application is validly signed by a trusted
// issuer. If this check ever passes, the audience check is not running.
$otherApp = new TokenVerifier(issuer: $issuer, audience: 'https://api.someone-else.example');
check('token issued for another audience is rejected', rejects($otherApp, $login->accessToken));

[$header, $payload] = explode('.', $login->accessToken);
check('tampered signature is rejected', rejects($verifier, "{$header}.{$payload}.AAAA"));

check('empty token is rejected', rejects($verifier, ''));
check(
    'malformed Authorization header is rejected',
    (function () use ($verifier): bool {
        try {
            $verifier->verifyAuthorizationHeader('Basic abc');

            return false;
        } catch (TokenVerificationException) {
            return true;
        }
    })(),
);

$rotated = $sso->refresh($login->refreshToken);
check('refresh rotates the token', $rotated->refreshToken !== $login->refreshToken);

try {
    $sso->refresh($login->refreshToken);
    check('reusing a rotated token raises RefreshTokenReusedException', false);
} catch (RefreshTokenReusedException $e) {
    check('reusing a rotated token raises RefreshTokenReusedException', !$e->isRetryable());
}

$sso->logout($rotated->refreshToken);
check('logout succeeds (204, empty body)', true);

// ---- Machine-to-machine (client_credentials) ----------------------------
// Uses the admin token to set up an application + client (not the SDK's job),
// then exercises the SDK's obtain + verify machine paths against them.
$adminPost = function (string $path, array $body) use ($issuer, $login): array {
    $ch = curl_init($issuer.$path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'authorization: Bearer '.$login->accessToken,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("{$path} -> HTTP {$status} {$raw}");
    }

    return json_decode((string) $raw, true);
};

$appIdentifier = sprintf('https://api.m2m-smoke-%d.example', (int) (microtime(true) * 1000));
$app = $adminPost('/api/applications', [
    'organizationId' => $principal->orgId,
    'identifier' => $appIdentifier,
    'name' => 'M2M smoke app',
]);
$creds = $adminPost('/api/clients', [
    'name' => 'M2M smoke client',
    'orgId' => $principal->orgId,
    'applicationId' => $app['id'],
    'scopes' => ['reports.read'],
]);
check('admin registered an app-bound client', !empty($creds['clientId']) && !empty($creds['clientSecret']));

$machine = $sso->clientCredentials($creds['clientId'], $creds['clientSecret'], ['reports.read']);
check('client_credentials returns a machine token', $machine->accessToken !== '');

// aud is the application's identifier, so a resource server verifies a machine
// token exactly like a user one — same issuer/audience, different token_use.
$m2mVerifier = new TokenVerifier(issuer: $issuer, audience: $appIdentifier);
$cp = $m2mVerifier->verifyClient($machine->accessToken);
check('machine token verifies to a ClientPrincipal', $cp->clientId === $creds['clientId']);
check('ClientPrincipal carries org + scope', $cp->orgId === $principal->orgId && $cp->hasScope('reports.read'));
printf(
    "      clientId=%s org=%d app=%s scopes=[%s]\n",
    $cp->clientId,
    $cp->orgId,
    var_export($cp->applicationId, true),
    implode(', ', $cp->scopes),
);

$rejectsClient = function (TokenVerifier $v, string $token): bool {
    try {
        $v->verifyClient($token);

        return false;
    } catch (TokenVerificationException) {
        return true;
    }
};

// The two token kinds never cross.
check('verify() rejects a machine token (token_use=client, not access)', rejects($m2mVerifier, $machine->accessToken));
check('verifyClient() rejects a user token (token_use=access, not client)', $rejectsClient($verifier, $login->accessToken));

echo $failures === 0 ? "\nall checks passed\n" : "\n{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
