<?php
return [
    // Master switch for the sandbox demo mode. Enable per environment via .env.
    'SANDBOX_DEMO_ENABLED' => env('SANDBOX_DEMO_ENABLED', 0),

    // Infrastructure tables (prefix stripped) always writable even while sandboxed,
    // so the app keeps working when SESSION/CACHE/QUEUE use the database driver.
    'infra_write_allowlist' => [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ],

    // Laravel File Manager routes that change files; blocked while sandboxed even
    // though many of them are GET requests (Layer B — see SandBoxMiddleware).
    'lfm_destructive_routes' => [
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
    ],
];
