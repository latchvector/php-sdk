<?php

declare(strict_types=1);

namespace LatchVector\Sso;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use LatchVector\Sso\Exception\ConfigurationException;
use LatchVector\Sso\Exception\ErrorMapper;
use LatchVector\Sso\Exception\RateLimitException;
use Throwable;

/**
 * Calls the authentication endpoints.
 *
 * Your API does not need this — it only needs TokenVerifier. This is for
 * whatever actually holds the user's password: a login controller, a BFF,
 * a mobile gateway.
 */
final class SsoClient
{
    private readonly string $issuer;
    private readonly ?string $wireAudience;
    private ClientInterface $http;

    /**
     * @param string $audience Your application's registered identifier. Set it equal to
     *                         $issuer to target the SSO service's own management API.
     *                         That is not a special case invented here — the service
     *                         issues management tokens with `aud` set to the issuer, so
     *                         verifying them with audience=issuer is correct, and this
     *                         keeps the two sides symmetrical.
     */
    public function __construct(
        string $issuer,
        string $audience,
        private readonly int $maxRateLimitRetries = 2,
        ?ClientInterface $httpClient = null,
        float $timeoutSeconds = 10.0,
    ) {
        if ($issuer === '') {
            throw new ConfigurationException('issuer is required');
        }
        if ($audience === '') {
            throw new ConfigurationException('audience is required');
        }
        $this->issuer = rtrim($issuer, '/');
        // The wire protocol says "omit audience to mean the management
        // API". Sending the issuer as a literal audience would be rejected
        // with unknown_audience, since the issuer is not an application.
        $this->wireAudience = rtrim($audience, '/') === $this->issuer ? null : $audience;
        $this->http = $httpClient ?? new HttpClient(['timeout' => $timeoutSeconds]);
    }

    public function login(string $email, string $password, ?DeviceInfo $device = null): LoginResult
    {
        return $this->toLoginResult($this->post('/api/auth/login', [
            'email' => $email,
            'password' => $password,
            'audience' => $this->wireAudience,
            'device' => $device?->toArray(),
        ]));
    }

    /**
     * Complete a login that returned MfaRequired.
     *
     * $code is either a six-digit TOTP or one of the user's recovery
     * codes; both are accepted here, so a "use a recovery code instead"
     * link needs no separate endpoint.
     */
    public function verifyMfa(string $pendingToken, string $code, ?DeviceInfo $device = null): TokenPair
    {
        $result = $this->toLoginResult($this->post('/api/auth/mfa/verify', [
            'pendingToken' => $pendingToken,
            'code' => $code,
            'audience' => $this->wireAudience,
            'device' => $device?->toArray(),
        ]));

        if (!$result instanceof TokenPair) {
            throw new ConfigurationException('MFA verification did not return tokens');
        }

        return $result;
    }

    /**
     * Exchange a Google or Microsoft ID token for ours.
     *
     * The user must already exist — accounts are provisioned by an
     * administrator. A first-time social login for an unknown email is
     * refused rather than silently creating an account.
     */
    public function socialLogin(string $provider, string $idToken, ?DeviceInfo $device = null): LoginResult
    {
        if (!in_array($provider, ['google', 'microsoft'], true)) {
            throw new ConfigurationException("Unsupported social provider: {$provider}");
        }

        return $this->toLoginResult($this->post("/api/auth/social/{$provider}", [
            'idToken' => $idToken,
            'audience' => $this->wireAudience,
            'device' => $device?->toArray(),
        ]));
    }

    /**
     * Exchange a refresh token for a new pair.
     *
     * The old token is dead the moment this returns. Persist the new one
     * before you use it — if the process dies in between, the user is
     * logged out, and if you keep the old one you will be treated as a
     * token thief on the next call. That is intended, not a bug.
     */
    public function refresh(string $refreshToken): TokenPair
    {
        return TokenPair::fromArray($this->post('/api/auth/refresh', [
            'refreshToken' => $refreshToken,
            'audience' => $this->wireAudience,
        ]));
    }

    /**
     * Revoke a refresh token.
     *
     * The current access token stays valid for the remainder of its 15
     * minutes — it is a signed bearer token, not a session. For immediate
     * cut-off, have an administrator disable the account.
     */
    public function logout(string $refreshToken): void
    {
        $this->post('/api/auth/logout', ['refreshToken' => $refreshToken]);
    }

    /**
     * Begin a password reset — the service emails a one-time link if the address
     * has an account. Reveals nothing: it resolves the same whether or not the
     * email exists, so it cannot be used to probe for accounts.
     */
    public function forgotPassword(string $email): void
    {
        $this->post('/api/auth/password/forgot', ['email' => $email]);
    }

    /** Set a new password from a one-time reset/invite token. */
    public function resetPassword(string $token, string $newPassword): void
    {
        $this->post('/api/auth/password/reset', ['token' => $token, 'newPassword' => $newPassword]);
    }

    /**
     * Obtain a machine-to-machine token with the OAuth2 client_credentials grant.
     *
     * For a backend job or service that acts as itself, not on behalf of a
     * user. The credentials come from registering an API client.
     *
     * A different wire shape from the user endpoints: HTTP Basic auth with
     * $clientId:$clientSecret, a form-encoded body, and the OAuth2
     * POST /oauth2/token endpoint. Verify the returned token with
     * TokenVerifier::verifyClient(), not verify().
     *
     * There is no refresh; when it expires, call this again. Cache it until
     * then rather than fetching one per request.
     *
     * @param list<string> $scopes
     */
    public function clientCredentials(string $clientId, string $clientSecret, array $scopes = []): MachineToken
    {
        if ($clientId === '' || $clientSecret === '') {
            throw new ConfigurationException('clientId and clientSecret are required');
        }

        $form = ['grant_type' => 'client_credentials'];
        if ($scopes !== []) {
            $form['scope'] = implode(' ', $scopes);
        }

        $attempt = 0;
        while (true) {
            $response = $this->http->request('POST', $this->issuer.'/oauth2/token', [
                'form_params' => $form,
                'auth' => [$clientId, $clientSecret],
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();
            $decoded = json_decode($raw, true);

            if ($status >= 200 && $status < 300) {
                return MachineToken::fromArray(is_array($decoded) ? $decoded : []);
            }

            // OAuth2 errors use {error, error_description}, not our {error, message}.
            $normalised = is_array($decoded)
                ? ['error' => $decoded['error'] ?? null, 'message' => $decoded['error_description'] ?? null]
                : null;
            $error = ErrorMapper::map($status, $normalised, $response->getHeaderLine('Retry-After') ?: null);

            if ($error instanceof RateLimitException && $attempt < $this->maxRateLimitRetries) {
                usleep((int) ($this->backoffSeconds($attempt, $error->retryAfterSeconds) * 1_000_000));
                ++$attempt;
                continue;
            }

            throw $error;
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */

    /**
     * The companies this user may act in.
     *
     * A person can belong to several companies of one customer and hold
     * different roles in each. Self-service: any valid access token may ask,
     * because seeing your own memberships reveals nothing you do not already
     * have.
     *
     * @return list<array{organizationId:int,organizationName:string,organizationPath:string,isDefault:bool}>
     */
    public function listMemberships(string $accessToken): array
    {
        /** @var list<array{organizationId:int,organizationName:string,organizationPath:string,isDefault:bool}> $rows */
        $rows = $this->send('GET', '/api/users/me/memberships', null, $accessToken);

        return $rows;
    }

    /**
     * Act as another of the caller's companies.
     *
     * A token carries the authority of exactly ONE company, so this is how a
     * user with several moves between them: the pair that comes back is scoped
     * to the new company and carries a different permission set.
     *
     * Anything you cached under the old token must be keyed by
     * `(uid, org_id)` — a cache keyed on `uid` alone will serve the previous
     * company's data to the new one. That is the one mistake this feature
     * invites, so it is worth checking before you ship.
     */
    public function switchOrganization(string $accessToken, int $organizationId): TokenPair
    {
        return TokenPair::fromArray($this->send('POST', '/api/users/me/active-organization', [
            'organizationId' => $organizationId,
            'audience' => $this->wireAudience,
        ], $accessToken));
    }

    private function post(string $path, array $body, ?string $accessToken = null): array
    {
        return $this->send('POST', $path, $body, $accessToken);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<mixed>
     */
    private function send(string $method, string $path, ?array $body, ?string $accessToken = null): array
    {
        $attempt = 0;

        while (true) {
            $options = ['http_errors' => false];
            if ($body !== null) {
                $options['json'] = $body;
            }
            if ($accessToken !== null) {
                $options['headers'] = ['Authorization' => 'Bearer '.$accessToken];
            }
            $response = $this->http->request($method, $this->issuer.$path, $options);
            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();

            if ($status >= 200 && $status < 300) {
                // logout answers 204 with no body at all, so this cannot
                // assume there is JSON to parse.
                if ($raw === '') {
                    return [];
                }

                try {
                    $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                } catch (Throwable $cause) {
                    throw new ConfigurationException(
                        'Malformed JSON from '.$path.': '.$cause->getMessage(),
                    );
                }

                return is_array($decoded) ? $decoded : [];
            }

            $decoded = json_decode($raw, true);
            $error = ErrorMapper::map(
                $status,
                is_array($decoded) ? $decoded : null,
                $response->getHeaderLine('Retry-After') ?: null,
            );

            // 429 is the only retryable code. Everything else is a
            // decision the service already made, and retrying it just
            // writes audit noise.
            if ($error instanceof RateLimitException && $attempt < $this->maxRateLimitRetries) {
                usleep((int) ($this->backoffSeconds($attempt, $error->retryAfterSeconds) * 1_000_000));
                ++$attempt;
                continue;
            }

            throw $error;
        }
    }

    /** Exponential backoff with full jitter, so retries do not synchronise. */
    private function backoffSeconds(int $attempt, ?float $retryAfter): float
    {
        if ($retryAfter !== null) {
            return $retryAfter;
        }

        return (mt_rand() / mt_getrandmax()) * min(2 ** $attempt, 8);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function toLoginResult(array $body): LoginResult
    {
        if (($body['mfaRequired'] ?? false) === true) {
            return new MfaRequired((string) ($body['pendingToken'] ?? ''));
        }

        return TokenPair::fromArray($body);
    }
}
