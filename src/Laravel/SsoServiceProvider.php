<?php

declare(strict_types=1);

namespace LatchVector\Sso\Laravel;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use LatchVector\Sso\Exception\ConfigurationException;
use LatchVector\Sso\SsoClient;
use LatchVector\Sso\Tenancy\TenantContext;
use LatchVector\Sso\TokenVerifier;

/**
 * Registers TokenVerifier and SsoClient as singletons.
 *
 * Auto-discovered via composer.json's extra.laravel.providers, so there is
 * nothing to add to config/app.php.
 *
 * Publish the config with:
 *   php artisan vendor:publish --tag=latchvector-sso-config
 */
final class SsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/latchvector-sso.php', 'latchvector-sso');

        // Singletons, so the JWKS is fetched once per process rather than
        // once per resolution. The PSR-16 cache matters more in PHP than
        // elsewhere: the process dies at the end of each request, so
        // without it every request would refetch the keys.
        $this->app->singleton(TokenVerifier::class, function ($app): TokenVerifier {
            $config = $app['config']['latchvector-sso'];
            $this->assertConfigured($config);

            return new TokenVerifier(
                issuer: $config['issuer'],
                audience: $config['audience'],
                leewaySeconds: $config['leeway_seconds'] ?? 30,
                jwksCacheSeconds: $config['jwks_cache_seconds'] ?? 600,
                cache: $this->psrCache($app),
            );
        });

        $this->app->singleton(SsoClient::class, function ($app): SsoClient {
            $config = $app['config']['latchvector-sso'];
            $this->assertConfigured($config);

            return new SsoClient(
                issuer: $config['issuer'],
                audience: $config['audience'],
            );
        });

        // One tenant context per request (the process dies each request, so a
        // singleton cannot leak across tenants). Its enabled flag comes from
        // config, so a sandbox can switch scoping off entirely.
        $this->app->singleton(TenantContext::class, function ($app): TenantContext {
            $context = new TenantContext();
            $context->configure((bool) ($app['config']['latchvector-sso']['tenant']['enabled'] ?? true));

            return $context;
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/latchvector-sso.php' => $this->app->configPath('latchvector-sso.php'),
        ], 'latchvector-sso-config');

        $router = $this->app['router'];
        // User tokens.
        $router->aliasMiddleware('sso.auth', AuthenticateWithSso::class);
        $router->aliasMiddleware('sso.can', RequirePermission::class);
        // Machine (client_credentials) tokens.
        $router->aliasMiddleware('sso.client', AuthenticateClientWithSso::class);
        $router->aliasMiddleware('sso.scope', RequireScope::class);
    }

    /** @param array<string, mixed> $config */
    private function assertConfigured(array $config): void
    {
        // Fail here rather than on the first request. A missing audience
        // that surfaces at runtime tends to be "fixed" by whatever makes
        // the error go away, and the thing that makes it go away is
        // turning off the check.
        foreach (['issuer', 'audience'] as $key) {
            if (empty($config[$key])) {
                throw new ConfigurationException(
                    "latchvector-sso.{$key} is not set. Set SSO_".strtoupper($key)." in your .env.",
                );
            }
        }
    }

    private function psrCache(mixed $app): ?\Psr\SimpleCache\CacheInterface
    {
        $cache = $app->make(CacheRepository::class);

        return $cache instanceof \Psr\SimpleCache\CacheInterface ? $cache : null;
    }
}
