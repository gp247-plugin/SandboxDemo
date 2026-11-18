<?php
return [
    // Master switch for the demo mode. Enable per environment via .env.
    'SANDBOX_DEMO_ENABLED' => env('SANDBOX_DEMO_ENABLED', 0),

    // Deny by default: while demo mode is on, ONLY the storefront may write. Everything
    // else — admin, admin REST API (any version), MCP, vendor/partner backends, any route
    // not listed below — is read-only, whoever is signed in.

    // Middleware groups that mark a storefront route.
    'storefront_route_groups' => [
        'front',
    ],

    // Path prefixes of the customer-facing REST API (register, cart, checkout, account).
    'storefront_path_prefixes' => [
        'api/front',
        'api/v2/front',
    ],

    // Storefront routes that live outside the "front" group. Add the webhook / return
    // routes of your payment or shipping plugins here if the demo should process them.
    'storefront_route_names' => [
        'shop.shipping.webhook',
    ],

    // Operator sign-in / sign-out routes: allowed so visitors can enter the demo admin.
    // Password reset and self-registration are deliberately not listed.
    'operator_auth_routes' => [
        'admin.login',
        'admin.post_login',
        'admin.logout',
        'vendor.login',
        'vendor.postLogin',
        'vendor.logout',
    ],

    // GET routes that change files or expose a full data dump; blocked on restricted
    // surfaces even though they are GET (every non-GET request there is blocked anyway).
    'blocked_get_routes' => [
        'unisharp.lfm.getDelete',
        'unisharp.lfm.getRename',
        'unisharp.lfm.move',
        'unisharp.lfm.domove',
        'unisharp.lfm.getResize',
        'unisharp.lfm.performResize',
        'unisharp.lfm.getCrop',
        'unisharp.lfm.getCropimage',
        'unisharp.lfm.getCropnewimage',
        'unisharp.lfm.getAddfolder',
        'unisharp.lfm.upload',
        'admin.backup.download',
    ],

    // Livewire methods refused on restricted surfaces, on any component. Livewire's own
    // upload built-ins by default: they mint signed upload URLs and store temporary files.
    'blocked_livewire_methods' => [
        '_startUpload',
        '_finishUpload',
        '_uploadErrored',
        '_removeUpload',
    ],

    // Livewire component namespaces that are view-only on restricted surfaces: no property
    // update and no call except `readonly_livewire_calls`. The backup module touches files
    // and remote hosts before it writes to the database, so it is locked as a whole.
    'blocked_livewire_namespaces' => [
        'GP247\\Core\\Backup\\',
    ],

    // Calls still allowed on a locked component (a plain re-render).
    'readonly_livewire_calls' => [
        '$refresh',
    ],

    // Infrastructure tables (prefix stripped) still writable on restricted surfaces, so
    // browsing keeps working when SESSION/CACHE use the database driver.
    'infra_write_allowlist' => [
        'sessions',
        'cache',
        'cache_locks',
    ],
];
