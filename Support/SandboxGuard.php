<?php
#App\GP247\Plugins\SandboxDemo\Support\SandboxGuard.php

namespace App\GP247\Plugins\SandboxDemo\Support;

use App\GP247\Plugins\SandboxDemo\Exceptions\SandboxWriteBlockedException;
use Illuminate\Http\Request;

/**
 * Core of the sandbox write-guard (ADR sandbox-demo_write-guard-layer, Layer A).
 *
 * Inspects every SQL statement passed through Connection::beforeExecuting and blocks
 * data-changing statements while the demo mode is active, so a logged-in operator can
 * browse the whole admin on GP247 v3 (Livewire) without persisting any change. The
 * decision is made on the SQL text only — no extra query is issued — so it is
 * transport-agnostic (Livewire, controller, or query-builder) and cheap.
 *
 * Scope is limited to the admin + admin-API surfaces: the DB hook is global and also
 * fires on storefront requests, so the guard must confirm the current request is an
 * admin one (by path prefix, or the host page's Referer for a Livewire update) before
 * enforcing — otherwise a storefront page that writes (e.g. a view counter) would be
 * blocked whenever an admin happens to be logged in the same browser.
 *
 * @aidlc-unit sandbox-demo-plugin
 * @aidlc-story US-sandbox-demo-block-db-writes
 * @aidlc-adr sandbox-demo_write-guard-layer
 */
class SandboxGuard
{
    /** Data-changing verbs — candidates for blocking. */
    private const WRITE_VERBS = [
        'insert', 'update', 'delete', 'replace', 'truncate', 'drop', 'alter', 'create', 'rename', 'merge',
    ];

    /** Read/scan verbs — always allowed. */
    private const READ_VERBS = ['select', 'show', 'explain', 'describe', 'desc', 'with', 'call'];

    /**
     * Re-entrancy flag: resolving the authenticated operator issues its own SELECTs,
     * which re-enter beforeExecuting; skip the guard while already inspecting.
     */
    private static bool $inspecting = false;

    /** Optional operator resolver seam (tests / hosts); null uses the default guards. */
    private static $operatorResolver = null;

    /**
     * Override how the guard decides an operator is logged in.
     *
     * @param callable|null $resolver Returns true when an admin/pmo/vendor is present; null restores default.
     * @return void
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-db-writes
     */
    public static function resolveOperatorUsing(?callable $resolver): void
    {
        self::$operatorResolver = $resolver;
    }

    /**
     * Whether the sandbox is currently enforcing (switch on AND an operator logged in).
     *
     * @return bool True when writes must be guarded.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-keep-infra
     */
    public static function isActive(): bool
    {
        // WHY: check the cheap config switch first so normal sites (demo off) pay
        // nothing beyond one array lookup per query.
        if (!config('Plugins/SandboxDemo.SANDBOX_DEMO_ENABLED')) {
            return false;
        }

        // WHY: the sandbox only governs the admin + admin-API surfaces. Everything
        // else (storefront, console, queue) must keep working, so a global DB write
        // is only guarded when it belongs to an admin request.
        if (!self::isAdminSurface(request())) {
            return false;
        }

        return self::hasAuthenticatedOperator();
    }

    /**
     * Whether the given request targets an admin or admin-API surface.
     *
     * Matches the request path against the configured admin prefixes; a Livewire
     * update posts to the framework endpoint (web group) rather than an admin path,
     * so its host page is read from the Referer header instead.
     *
     * @param Request|null $request Current request, or null in a context without one.
     * @return bool True when the request belongs to an admin/admin-API surface.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-scope-admin-only
     */
    public static function isAdminSurface(?Request $request): bool
    {
        if ($request === null) {
            return false;
        }

        $prefixes = self::adminSurfacePrefixes();

        if (self::pathHasPrefix(ltrim($request->path(), '/'), $prefixes)) {
            return true;
        }

        // WHY: Livewire posts every component update to its own endpoint (web group),
        // so the request path is never an admin one — fall back to the host page in
        // the Referer to tell an admin component from a storefront one.
        if ($request->hasHeader('X-Livewire')) {
            return self::pathHasPrefix(self::refererPath($request), $prefixes);
        }

        return false;
    }

    /**
     * Inspect one SQL statement and block it when the active sandbox forbids the write.
     *
     * @param string $query Raw SQL about to execute (as given by Connection::beforeExecuting).
     * @return void
     * @throws SandboxWriteBlockedException When a forbidden write is attempted while active.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-db-writes
     */
    public static function inspect(string $query): void
    {
        if (self::$inspecting) {
            return;
        }

        self::$inspecting = true;
        try {
            if (!self::isActive()) {
                return;
            }
            if (self::wouldBlock($query)) {
                throw new SandboxWriteBlockedException();
            }
        } finally {
            self::$inspecting = false;
        }
    }

    /**
     * Pure decision: would this statement be blocked in an active sandbox?
     *
     * Ignores activation so it can be unit-tested in isolation. A write verb whose
     * target table cannot be parsed is blocked (fail-safe).
     *
     * @param string $query Raw SQL statement.
     * @return bool True when the statement changes data and is not on the infra allowlist.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-db-writes
     */
    public static function wouldBlock(string $query): bool
    {
        if (self::classify($query) !== 'write') {
            return false;
        }

        $table = self::extractTable($query);
        if ($table !== null && in_array($table, self::allowlist(), true)) {
            return false;
        }

        return true;
    }

    /**
     * Classify a statement by its leading verb.
     *
     * @param string $query Raw SQL statement.
     * @return string One of "write", "read", "control" (control = unknown/transaction/session verbs, always allowed).
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-keep-view
     */
    public static function classify(string $query): string
    {
        $verb = self::firstVerb($query);
        if ($verb === null) {
            return 'control';
        }
        if (in_array($verb, self::WRITE_VERBS, true)) {
            return 'write';
        }
        if (in_array($verb, self::READ_VERBS, true)) {
            return 'read';
        }

        // WHY: unknown verbs (set/commit/use/lock/analyze…) are not data writes we
        // target; allow them so reads and transaction control never break viewing.
        return 'control';
    }

    /**
     * Extract the target table (prefix stripped) of a write statement.
     *
     * @param string $query Raw SQL statement.
     * @return string|null Table name without the connection prefix, or null when unparseable.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-keep-infra
     */
    public static function extractTable(string $query): ?string
    {
        $patterns = [
            '/^\s*insert\s+(?:ignore\s+)?into\s+`?([a-zA-Z0-9_]+)`?/i',
            '/^\s*replace\s+(?:into\s+)?`?([a-zA-Z0-9_]+)`?/i',
            '/^\s*update\s+(?:ignore\s+)?`?([a-zA-Z0-9_]+)`?\s+set\b/i',
            '/^\s*delete\s+from\s+`?([a-zA-Z0-9_]+)`?/i',
            '/^\s*truncate\s+(?:table\s+)?`?([a-zA-Z0-9_]+)`?/i',
            '/^\s*(?:drop|alter|create|rename)\s+(?:table\s+)?(?:if\s+(?:not\s+)?exists\s+)?`?([a-zA-Z0-9_]+)`?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query, $matches)) {
                return self::stripPrefix($matches[1]);
            }
        }

        return null;
    }

    /**
     * Whether an admin, PMO partner, or vendor is logged in.
     *
     * @return bool True when at least one operator guard has a user.
     */
    private static function hasAuthenticatedOperator(): bool
    {
        if (self::$operatorResolver !== null) {
            return (bool) call_user_func(self::$operatorResolver);
        }

        return (function_exists('admin') && admin()->user())
            || (function_exists('pmo_partner') && pmo_partner()->user())
            || (function_exists('vendor') && vendor()->user());
    }

    /**
     * First SQL keyword, lowercased, skipping leading block comments and parentheses.
     *
     * @param string $query Raw SQL statement.
     * @return string|null Leading verb, or null when none found.
     */
    private static function firstVerb(string $query): ?string
    {
        $sql = ltrim($query);

        // Strip leading block comments Laravel/drivers may prepend.
        while (strncmp($sql, '/*', 2) === 0) {
            $end = strpos($sql, '*/');
            if ($end === false) {
                break;
            }
            $sql = ltrim(substr($sql, $end + 2));
        }

        $sql = ltrim($sql, "( \t\n\r");
        if (!preg_match('/^([a-zA-Z]+)/', $sql, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * Remove the connection table prefix so names compare against the allowlist.
     *
     * @param string $table Table name possibly carrying the GP247 prefix.
     * @return string Table name without the prefix.
     */
    private static function stripPrefix(string $table): string
    {
        $prefix = defined('GP247_DB_PREFIX') ? GP247_DB_PREFIX : '';
        if ($prefix !== '' && strncmp($table, $prefix, strlen($prefix)) === 0) {
            return substr($table, strlen($prefix));
        }

        return $table;
    }

    /**
     * Infrastructure tables always allowed to be written even while active.
     *
     * @return string[] Table names (prefix stripped).
     */
    private static function allowlist(): array
    {
        return (array) config('Plugins/SandboxDemo.infra_write_allowlist', []);
    }

    /**
     * Whether a request/referer path starts with one of the admin surface prefixes.
     *
     * @param string   $path     Path with no leading slash (e.g. "gp247_admin/product").
     * @param string[] $prefixes Admin surface prefixes (e.g. ["gp247_admin", "api"]).
     * @return bool True when the path is exactly a prefix or sits under one.
     */
    private static function pathHasPrefix(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix === '') {
                continue;
            }
            if ($path === $prefix || strncmp($path, $prefix . '/', strlen($prefix) + 1) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Path portion of the request's Referer header, without a leading slash.
     *
     * @param Request $request Current request.
     * @return string Referer path (e.g. "gp247_admin/discount"), or "" when absent.
     */
    private static function refererPath(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        if ($referer === '') {
            return '';
        }

        return ltrim((string) parse_url($referer, PHP_URL_PATH), '/');
    }

    /**
     * Admin surface path prefixes: the configured list plus the live admin prefix
     * constant and any installed vendor/partner backend prefixes.
     *
     * @return string[] Prefixes without a leading slash.
     */
    private static function adminSurfacePrefixes(): array
    {
        $prefixes = (array) config('Plugins/SandboxDemo.admin_surface_prefixes', []);

        if (defined('GP247_ADMIN_PREFIX')) {
            $prefixes[] = GP247_ADMIN_PREFIX;
        }

        // Optional sibling backends (multi-vendor, PMO partner) declare their own
        // admin path; include them when those extensions are installed.
        foreach (['Plugins/MultiVendorPro.route.MULTIVENDOR_ADMIN_PATH', 'Plugins/PmoPartner.route.PARTNER_ADMIN_PATH'] as $configKey) {
            $value = config($configKey);
            if (is_string($value) && $value !== '') {
                $prefixes[] = $value;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($prefix): string => ltrim((string) $prefix, '/'),
            $prefixes
        ))));
    }
}
