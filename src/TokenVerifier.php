<?php

declare(strict_types=1);

namespace LatchVector\Sso;

use DateTimeImmutable;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use LatchVector\Sso\Exception\ConfigurationException;
use LatchVector\Sso\Exception\TokenVerificationException;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Verifies access tokens locally, without calling the SSO service.
 *
 * Construct once and reuse — it caches both the discovery document and the
 * signing keys. Constructing one per request would fetch the JWKS every
 * time and make the SSO service a hard dependency of your request path,
 * which is exactly what local verification avoids. In PHP, where the
 * process dies at the end of each request, pass a PSR-16 cache so the keys
 * survive between requests.
 */
final class TokenVerifier
{
    private const DISCOVERY_PATH = '/.well-known/openid-configuration';

    private readonly string $issuer;
    private ClientInterface $http;

    /** @var array<string, \Firebase\JWT\Key>|null */
    private ?array $keys = null;

    /**
     * @param string $issuer   Issuer URL of the SSO service. The only URL you configure.
     * @param string $audience Your application's registered identifier. Required, and
     *                         there is no way to disable the check — a token issued for
     *                         a different application is validly signed by a trusted
     *                         issuer, so without this you would accept one from every
     *                         user of every other application on the platform.
     *                         See CONTRACT.md section 2.
     */
    public function __construct(
        string $issuer,
        private readonly string $audience,
        private readonly int $leewaySeconds = 30,
        private readonly int $jwksCacheSeconds = 600,
        ?ClientInterface $httpClient = null,
        private readonly ?CacheInterface $cache = null,
    ) {
        if ($issuer === '') {
            throw new ConfigurationException('issuer is required');
        }
        if ($audience === '') {
            throw new ConfigurationException(
                'audience is required. It is your application identifier, and the check '
                .'cannot be disabled — without it any token from any application on the '
                .'platform would be accepted.',
            );
        }
        $this->issuer = rtrim($issuer, '/');
        $this->http = $httpClient ?? new HttpClient(['timeout' => 10]);
    }

    /**
     * Verify a raw JWT and return the principal.
     *
     * All four mandatory checks run: signature, issuer, audience, token_use.
     *
     * @throws TokenVerificationException on any failure
     */
    public function verify(string $token): Principal
    {
        if ($token === '') {
            throw new TokenVerificationException('No token supplied');
        }

        JWT::$leeway = $this->leewaySeconds;

        try {
            // Signature, exp, nbf and iat only. firebase/php-jwt does NOT
            // check iss or aud — unlike jose and PyJWT — so those are
            // enforced explicitly below. Leaving them to the library here
            // would be the single most dangerous assumption in this SDK.
            $claims = (array) JWT::decode($token, $this->keys());
        } catch (Throwable $cause) {
            throw new TokenVerificationException(
                'Token verification failed: '.$cause->getMessage(),
            );
        }

        $this->assertIssuer($claims);
        $this->assertAudience($claims);

        // Defence in depth. An MFA pending token carries no `aud`, so the
        // audience check above already rejects it — but that is a side
        // effect, and this makes the intent explicit and independent of it.
        if (($claims['token_use'] ?? null) !== 'access') {
            throw new TokenVerificationException(sprintf(
                'Expected an access token, got token_use=%s. An MFA pending token is not a login.',
                var_export($claims['token_use'] ?? null, true),
            ));
        }

        return $this->toPrincipal($claims);
    }

    /** Verify the token from an `Authorization: Bearer …` header value. */
    public function verifyAuthorizationHeader(?string $header): Principal
    {
        if ($header === null || !preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            throw new TokenVerificationException('Missing or malformed Authorization header');
        }

        return $this->verify($m[1]);
    }

    /**
     * Verify a machine (client_credentials) token and return a ClientPrincipal.
     *
     * The same signature, issuer and audience checks as verify() — a client
     * bound to an application carries that application's identifier as `aud`,
     * so it is checked identically — but `token_use` must be `client`, not
     * `access`. A user token is rejected here, and a machine token is rejected
     * by verify(): the two kinds never cross.
     */
    public function verifyClient(string $token): ClientPrincipal
    {
        if ($token === '') {
            throw new TokenVerificationException('No token supplied');
        }

        JWT::$leeway = $this->leewaySeconds;

        try {
            $claims = (array) JWT::decode($token, $this->keys());
        } catch (Throwable $cause) {
            throw new TokenVerificationException(
                'Token verification failed: '.$cause->getMessage(),
            );
        }

        $this->assertIssuer($claims);
        $this->assertAudience($claims);

        if (($claims['token_use'] ?? null) !== 'client') {
            throw new TokenVerificationException(sprintf(
                'Expected a machine token, got token_use=%s. A user access token is not a client_credentials token.',
                var_export($claims['token_use'] ?? null, true),
            ));
        }

        return $this->toClientPrincipal($claims);
    }

    /** Verify a machine token from an `Authorization: Bearer …` header value. */
    public function verifyClientAuthorizationHeader(?string $header): ClientPrincipal
    {
        if ($header === null || !preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            throw new TokenVerificationException('Missing or malformed Authorization header');
        }

        return $this->verifyClient($m[1]);
    }

    /** @param array<string, mixed> $claims */
    private function assertIssuer(array $claims): void
    {
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new TokenVerificationException('Token issuer does not match the configured issuer');
        }
    }

    /** @param array<string, mixed> $claims */
    private function assertAudience(array $claims): void
    {
        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];

        foreach ($audiences as $candidate) {
            // hash_equals, not ===, so the comparison does not leak the
            // matching prefix length through timing.
            if (is_string($candidate) && hash_equals($this->audience, $candidate)) {
                return;
            }
        }

        throw new TokenVerificationException(
            'Token was not issued for this application. Expected audience "'.$this->audience.'".',
        );
    }

    /**
     * Resolve the JWKS URI from discovery rather than assuming a path.
     *
     * Hardcoding /oauth2/jwks would break silently if the endpoint ever
     * moved; discovery makes that class of bug impossible.
     *
     * @return array<string, \Firebase\JWT\Key>
     */
    private function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $cacheKey = 'latchvector_sso_jwks_'.md5($this->issuer);
        $cached = $this->cache?->get($cacheKey);
        if (is_array($cached)) {
            // Pinned to RS256 here. Without an explicit algorithm a token
            // claiming HS256 could be verified using the public key as an
            // HMAC secret — and the public key is published at the JWKS
            // endpoint, so anyone could mint one.
            return $this->keys = JWK::parseKeySet($cached, 'RS256');
        }

        $url = $this->issuer.self::DISCOVERY_PATH;
        try {
            $discovery = json_decode(
                (string) $this->http->request('GET', $url)->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $cause) {
            // Not cached: a transient outage would otherwise poison the
            // verifier for as long as the cache entry lived.
            throw new ConfigurationException(
                "Could not read the OpenID discovery document at {$url}: ".$cause->getMessage(),
            );
        }

        $jwksUri = $discovery['jwks_uri'] ?? null;
        if (!is_string($jwksUri) || $jwksUri === '') {
            throw new ConfigurationException("Discovery document at {$url} has no jwks_uri");
        }

        try {
            $jwks = json_decode(
                (string) $this->http->request('GET', $jwksUri)->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $cause) {
            throw new ConfigurationException(
                "Could not read the JWKS at {$jwksUri}: ".$cause->getMessage(),
            );
        }

        $this->cache?->set($cacheKey, $jwks, $this->jwksCacheSeconds);

        return $this->keys = JWK::parseKeySet($jwks, 'RS256');
    }

    /** @param array<string, mixed> $claims */
    private function toPrincipal(array $claims): Principal
    {
        return new Principal(
            uid: $this->requireInt($claims, 'uid'),
            email: is_string($claims['sub'] ?? null) ? $claims['sub'] : '',
            orgId: $this->requireInt($claims, 'org_id'),
            tenantId: $this->requireInt($claims, 'tenant_id'),
            orgPath: is_string($claims['org_path'] ?? null) ? $claims['org_path'] : '',
            permissions: $this->stringList($claims, 'permissions'),
            scopeSelf: $this->stringList($claims, 'scope_self'),
            scopeSubtree: $this->stringList($claims, 'scope_subtree'),
            expiresAt: new DateTimeImmutable('@'.(int) ($claims['exp'] ?? 0)),
            claims: $claims,
        );
    }

    /** @param array<string, mixed> $claims */
    private function toClientPrincipal(array $claims): ClientPrincipal
    {
        $applicationId = $claims['application_id'] ?? null;

        return new ClientPrincipal(
            clientId: is_string($claims['sub'] ?? null) ? $claims['sub'] : '',
            orgId: $this->requireInt($claims, 'org_id'),
            tenantId: $this->requireInt($claims, 'tenant_id'),
            scopes: $this->stringList($claims, 'scope'),
            expiresAt: new DateTimeImmutable('@'.(int) ($claims['exp'] ?? 0)),
            applicationId: is_int($applicationId) ? $applicationId : null,
            claims: $claims,
        );
    }

    /** @param array<string, mixed> $claims */
    private function requireInt(array $claims, string $name): int
    {
        $value = $claims[$name] ?? null;
        if (!is_int($value) && !(is_float($value) && floor($value) === $value)) {
            throw new TokenVerificationException("Token is missing the numeric claim \"{$name}\"");
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return list<string>
     */
    private function stringList(array $claims, string $name): array
    {
        $value = $claims[$name] ?? null;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
