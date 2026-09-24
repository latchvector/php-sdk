<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests;

use Firebase\JWT\Key;
use LatchVector\Sso\Exception\TokenVerificationException;
use LatchVector\Sso\Tests\Support\Jwk;
use LatchVector\Sso\TokenVerifier;
use LatchVector\Sso\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

/**
 * The shared conformance corpus, run against this SDK.
 *
 * `conformance/` is a vendored copy of a corpus every Latch Vector SDK carries. The same
 * vectors are asserted by the Node, Python, Java and .NET SDKs, each with its own runner
 * in its own language — the repositories have no dependency on one another.
 *
 * A failure here is either a bug in this SDK or a deliberate divergence from the other
 * four, and there is no third option. That is the point of sharing the file.
 *
 * Only key resolution is stubbed. Every signature check is real.
 */
final class ConformanceTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function corpus(string $file): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../conformance/' . $file),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function tokenVectors(): array
    {
        $cases = [];
        foreach (self::corpus('vectors.json')['vectors'] as $vector) {
            $cases[$vector['name']] = [$vector];
        }

        return $cases;
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function webhookVectors(): array
    {
        $cases = [];
        foreach (self::corpus('webhook-vectors.json')['vectors'] as $vector) {
            $cases[$vector['name']] = [$vector];
        }

        return $cases;
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('tokenVectors')]
    public function testTokenVector(array $vector): void
    {
        $spec = self::corpus('vectors.json');
        $keys = self::corpus('keys.json');

        $privatePem = [
            'primary' => Jwk::toPrivatePem($keys['primary']['private']),
            'attacker' => Jwk::toPrivatePem($keys['attacker']['private']),
        ];
        $publicPem = Jwk::toPublicPem($keys['primary']['public']);

        $token = $this->buildToken($vector, $spec, $privatePem, $publicPem);

        $verifier = new TokenVerifier(
            $spec['config']['issuer'],
            $spec['config']['audience'],
            $spec['config']['clockToleranceSeconds'],
        );

        // The one thing stubbed: which keys we trust.
        $property = (new ReflectionObject($verifier))->getProperty('keys');
        $property->setValue($verifier, ['lv-test-primary' => new Key($publicPem, 'RS256')]);

        $asClient = $vector['verify'] === 'client';
        $because = "\n" . $vector['reason'];

        if ($vector['expect'] === 'reject') {
            $this->expectException(TokenVerificationException::class);
            $asClient ? $verifier->verifyClient($token) : $verifier->verify($token);

            return;
        }

        $principal = $asClient ? $verifier->verifyClient($token) : $verifier->verify($token);

        // Acceptance is itself the assertion for vectors that state no fields.
        self::assertNotNull($principal, 'expected acceptance' . $because);

        foreach ($vector['principal'] ?? [] as $field => $expected) {
            $actual = $principal->{self::fieldName($field)};
            if (is_array($expected)) {
                self::assertSame($expected, array_values((array) $actual), $field . $because);
            } else {
                self::assertSame($expected, $actual, $field . $because);
            }
        }

        foreach ($vector['reach'] ?? [] as $path => $expected) {
            self::assertSame(
                $expected,
                $principal->canReach((string) $path),
                sprintf('canReach(%s)%s', json_encode($path), $because),
            );
        }
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('webhookVectors')]
    public function testWebhookVector(array $vector): void
    {
        $reference = time();
        $timestamp = (string) ($reference + $vector['timestampOffset']);
        $body = $vector['body'];
        $secret = $vector['secret'];

        $hmac = static fn (string $key, string $ts, string $payload): string
            => 'sha256=' . hash_hmac('sha256', $ts . '.' . $payload, $key);

        $spec = $vector['signature'] ?? null;
        if ($spec === null) {
            $signature = null;
        } elseif (is_array($spec)) {
            $signature = $spec['literal'];
        } else {
            $signature = match ($spec) {
                'valid' => $hmac($secret, $timestamp, $body),
                'other_secret' => $hmac($secret . '-wrong', $timestamp, $body),
                'other_body' => $hmac($secret, $timestamp, $body . ' '),
                'other_timestamp' => $hmac($secret, (string) ((int) $timestamp - 60), $body),
                default => self::fail('unknown signature spec ' . $spec),
            };
        }

        $timestampHeader = ($vector['omitTimestamp'] ?? false)
            ? null
            : ($vector['literalTimestamp'] ?? $timestamp);

        $actual = Webhooks::verify(
            $body,
            $signature,
            $timestampHeader,
            $secret,
            $vector['toleranceSeconds'] ?? 300,
        );

        self::assertSame($vector['expect'], $actual, "\n" . $vector['reason']);
    }

    /** Maps the corpus's neutral field names onto this SDK's PHP naming. */
    private static function fieldName(string $field): string
    {
        return match ($field) {
            'uid' => 'uid',
            'email' => 'email',
            'orgId' => 'orgId',
            'tenantId' => 'tenantId',
            'orgPath' => 'orgPath',
            'permissions' => 'permissions',
            'scopeSelf' => 'scopeSelf',
            'scopeSubtree' => 'scopeSubtree',
            'clientId' => 'clientId',
            'applicationId' => 'applicationId',
            'scopes' => 'scopes',
            default => self::fail('corpus names an unknown principal field ' . $field),
        };
    }

    /**
     * @param array<string, mixed> $vector
     * @param array<string, mixed> $spec
     * @param array<string, string> $privatePem
     */
    private function buildToken(array $vector, array $spec, array $privatePem, string $publicPem): string
    {
        $resolve = static function (mixed $value): mixed {
            if (is_string($value) && preg_match('/^[+-]\d+$/', $value) === 1) {
                return time() + (int) $value;
            }

            return $value;
        };

        $payload = [];
        foreach ($spec['templates'][$vector['base']] as $claim => $value) {
            $payload[$claim] = $resolve($value);
        }
        foreach ($vector['claims'] ?? [] as $claim => $value) {
            if ($value === null) {
                unset($payload[$claim]);
            } else {
                $payload[$claim] = $resolve($value);
            }
        }

        // Padding goes in BEFORE signing, so the token is genuinely well-formed and
        // validly signed — merely enormous. That is what makes the size ceiling the
        // only thing that can reject it.
        if (!empty($vector['padClaimBytes'])) {
            $payload['_padding'] = str_repeat('A', $vector['padClaimBytes']);
        }

        $keyName = $vector['sign']['key'] ?? 'primary';
        $alg = $vector['sign']['alg'] ?? 'RS256';
        $header = array_merge(
            [
                'alg' => $alg,
                'typ' => 'JWT',
                'kid' => $keyName === 'attacker' ? 'lv-test-attacker' : 'lv-test-primary',
            ],
            $vector['sign']['header'] ?? [],
        );

        $sign = static function (array $h, array $p, string $key, string $algorithm) use ($privatePem, $publicPem): string {
            $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
            $input = $b64(json_encode($h, JSON_UNESCAPED_SLASHES)) . '.' . $b64(json_encode($p, JSON_UNESCAPED_SLASHES));

            if ($algorithm === 'none') {
                return $input . '.';
            }
            if ($algorithm === 'HS256') {
                // Algorithm confusion: the HMAC secret is the RSA public key, which
                // anyone can fetch from the JWKS endpoint.
                return $input . '.' . $b64(hash_hmac('sha256', $input, $publicPem, true));
            }

            openssl_sign($input, $signature, $privatePem[$key], OPENSSL_ALGO_SHA256);

            return $input . '.' . $b64($signature);
        };

        $token = $sign($header, $payload, $keyName, $alg);
        $parts = explode('.', $token);
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return match ($vector['mutate'] ?? null) {
            null => $token,
            'alg_none' => $sign(array_merge($header, ['alg' => 'none']), $payload, $keyName, 'none'),
            'alg_hs256_public_key' => $sign(array_merge($header, ['alg' => 'HS256']), $payload, $keyName, 'HS256'),
            'tamper_payload' => $parts[0] . '.'
                . $b64(json_encode(array_merge($payload, ['uid' => 1]), JSON_UNESCAPED_SLASHES))
                . '.' . $parts[2],
            'strip_signature' => $parts[0] . '.' . $parts[1] . '.',
            'truncate_signature' => $parts[0] . '.' . $parts[1] . '.' . substr($parts[2], 0, -8),
            'garbage' => 'this-is-not-a-jwt',
            'empty' => '',
            'whitespace' => '     ',
            'huge' => str_repeat('A', 10 * 1024 * 1024),
            default => self::fail('unknown mutation ' . $vector['mutate']),
        };
    }
}
