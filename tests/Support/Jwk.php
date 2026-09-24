<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests\Support;

/**
 * Turns a JWK from the conformance corpus into a PEM.
 *
 * The corpus publishes RSA keys as JWKs because that is the shape a JWKS endpoint serves,
 * and firebase/php-jwt signs with PEMs. This converts between the two by assembling the
 * DER structures directly, so the test keys are exactly the bytes the committed corpus
 * contains rather than whatever a helper library happens to produce.
 *
 * Test-support only. Nothing in src/ needs this: the SDK reads a JWKS through
 * Firebase\JWT\JWK, which handles the conversion itself.
 */
final class Jwk
{
    /** @param array<string, string> $jwk */
    public static function toPrivatePem(array $jwk): string
    {
        $part = static fn (string $member): string => self::asn1Integer(self::b64u($jwk[$member]));

        $body = self::asn1Sequence(
            self::asn1Integer("\x00")
            . $part('n') . $part('e') . $part('d')
            . $part('p') . $part('q')
            . $part('dp') . $part('dq') . $part('qi')
        );

        return self::pem('RSA PRIVATE KEY', $body);
    }

    /** @param array<string, string> $jwk */
    public static function toPublicPem(array $jwk): string
    {
        $rsaEncryptionOid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        $keySequence = self::asn1Sequence(
            self::asn1Integer(self::b64u($jwk['n'])) . self::asn1Integer(self::b64u($jwk['e']))
        );

        // A BIT STRING wrapping the key sequence, with the leading "no unused bits" octet.
        $bitString = "\x03" . self::asn1Length(strlen($keySequence) + 1) . "\x00" . $keySequence;

        return self::pem('PUBLIC KEY', self::asn1Sequence(
            self::asn1Sequence($rsaEncryptionOid) . $bitString
        ));
    }

    private static function b64u(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true)
            ?: throw new \InvalidArgumentException('malformed base64url in the corpus JWK');
    }

    private static function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function asn1Integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        // DER integers are signed, so a leading bit of 1 needs a zero octet in front or
        // the value reads as negative.
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::asn1Length(strlen($bytes)) . $bytes;
    }

    private static function asn1Sequence(string $contents): string
    {
        return "\x30" . self::asn1Length(strlen($contents)) . $contents;
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END {$label}-----\n";
    }
}
