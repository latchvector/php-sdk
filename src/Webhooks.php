<?php

declare(strict_types=1);

namespace LatchVector\Sso;

/**
 * Verify an inbound webhook from the SSO service.
 *
 * Each delivery is signed: the SSO computes
 * {@code HMAC-SHA256("{timestamp}.{rawBody}", secret)} and sends it as
 * {@code X-LatchVector-Signature: sha256=<hex>}, with the unix time in
 * {@code X-LatchVector-Timestamp}. Verify BOTH — the signature (authenticity)
 * and the timestamp freshness (so a captured delivery cannot be replayed later).
 *
 *   use LatchVector\Sso\Webhooks;
 *
 *   $raw = file_get_contents('php://input');           // the RAW body
 *   $ok = Webhooks::verify(
 *       $raw,
 *       $_SERVER['HTTP_X_LATCHVECTOR_SIGNATURE'] ?? null,
 *       $_SERVER['HTTP_X_LATCHVECTOR_TIMESTAMP'] ?? null,
 *       getenv('WEBHOOK_SECRET'),
 *   );
 *   if (! $ok) { http_response_code(400); exit; }
 *   $event = json_decode($raw, true);                  // $event['type'], $event['data']
 */
final class Webhooks
{
    /**
     * True when the signature matches and (unless $toleranceSeconds is 0) the
     * timestamp is fresh. $rawBody must be the exact bytes received — decoding
     * and re-encoding JSON changes them and breaks the signature.
     */
    public static function verify(
        string $rawBody,
        ?string $signatureHeader,
        ?string $timestampHeader,
        string $secret,
        int $toleranceSeconds = 300
    ): bool {
        if ($signatureHeader === null || $timestampHeader === null) {
            return false;
        }

        if ($toleranceSeconds > 0) {
            if (! ctype_digit(ltrim($timestampHeader, '-'))) {
                return false;
            }
            if (abs(time() - (int) $timestampHeader) > $toleranceSeconds) {
                return false;
            }
        }

        $expected = 'sha256=' . hash_hmac('sha256', $timestampHeader . '.' . $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
