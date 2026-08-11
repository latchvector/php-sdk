<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// In a real Laravel app these come from illuminate/foundation. We test the SDK's
// Eloquent scope standalone (no full framework), so provide the same two helpers
// backed by the container the test sets up. Guarded so a real app never clashes.
if (!function_exists('app')) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        $container = \Illuminate\Container\Container::getInstance();

        return $abstract === null ? $container : $container->make($abstract, $parameters);
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        $repository = \Illuminate\Container\Container::getInstance()->make('config');

        return $key === null ? $repository : $repository->get($key, $default);
    }
}
