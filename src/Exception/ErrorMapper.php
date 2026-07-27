<?php

declare(strict_types=1);

namespace LatchVector\Sso\Exception;

/** Maps a service error response onto the right exception class. */
final class ErrorMapper
{
    private const AUTHENTICATION_CODES = [
        'invalid_credentials',
        'invalid_code',
        'invalid_id_token',
        'invalid_token',
        'invalid_token_use',
        'invalid_or_expired_pending_token',
    ];

    private const REFRESH_CODES = [
        'invalid_refresh_token',
        'refresh_token_expired',
    ];

    /** @param array<string, mixed>|null $body */
    public static function map(int $status, ?array $body, ?string $retryAfterHeader = null): SsoException
    {
        $code = is_string($body['error'] ?? null) ? $body['error'] : 'unknown_error';
        $message = is_string($body['message'] ?? null) ? $body['message'] : $code;

        if ($status === 429) {
            return new RateLimitException(
                is_numeric($retryAfterHeader) ? (float) $retryAfterHeader : null,
            );
        }

        if ($code === 'validation_failed') {
            $fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];

            return new ValidationException($message, $fields);
        }

        return match (true) {
            $code === 'refresh_token_reused' => new RefreshTokenReusedException($message),
            in_array($code, self::REFRESH_CODES, true) => new RefreshTokenException($code, 401, $message),
            in_array($code, self::AUTHENTICATION_CODES, true) => new AuthenticationException($code, 401, $message),
            $code === 'account_not_active' => new AccountNotActiveException($code, 403, $message),
            $code === 'account_locked' => new AccountLockedException($code, 423, $message),
            $code === 'access_denied' => new AccessDeniedException($code, 403, $message),
            $code === 'unknown_audience' => new ConfigurationException(
                $message.' — the configured audience is not a registered application. '
                .'Check the identifier with your platform administrator.',
            ),
            default => new SsoException($code, $status, $message),
        };
    }
}
