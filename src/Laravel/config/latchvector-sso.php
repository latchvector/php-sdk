<?php

declare(strict_types=1);

return [
    /*
     * Issuer URL of the SSO service. The only URL you configure — the JWKS
     * endpoint is resolved from {issuer}/.well-known/openid-configuration.
     */
    'issuer' => env('SSO_ISSUER'),

    /*
     * Your application's registered identifier.
     *
     * Required, and the check cannot be disabled. A token issued for a
     * different application is validly signed by a trusted issuer — without
     * this you would accept one from every user of every other application
     * on the platform.
     */
    'audience' => env('SSO_AUDIENCE'),

    /*
     * Clock skew allowance, in seconds. Keep NTP running regardless; this
     * is a cushion, not a substitute.
     */
    'leeway_seconds' => (int) env('SSO_LEEWAY_SECONDS', 30),

    /*
     * How long to cache the signing keys. Cached through Laravel's cache,
     * which matters more in PHP than elsewhere: without it every request
     * would refetch the JWKS, since the process dies each time.
     */
    'jwks_cache_seconds' => (int) env('SSO_JWKS_CACHE_SECONDS', 600),

    /*
     * Data-layer multitenancy. When a model uses the BelongsToTenant trait,
     * its queries are confined to the tenant in the caller's verified token,
     * and new rows are stamped with it — so a query cannot see or write another
     * tenant's data even if you forget to add the where clause.
     */
    'tenant' => [
        /*
         * Master switch. Turn OFF in a sandbox / local dev so testing is not
         * constrained to one tenant. ON everywhere data is real.
         */
        'enabled' => (bool) env('SSO_TENANT_SCOPING', true),

        /*
         * The column every tenant-aware table carries. It holds the tenant_id
         * from the token (the hard isolation boundary).
         */
        'column' => env('SSO_TENANT_COLUMN', 'tenant_id'),

        /*
         * Callers holding any of these permissions are exempt from the scope —
         * they see across tenants. This is for platform operators, not ordinary
         * admins: an org admin is still bound to their own tenant.
         */
        'bypass_permissions' => ['PLATFORM_ADMIN'],
    ],
];
