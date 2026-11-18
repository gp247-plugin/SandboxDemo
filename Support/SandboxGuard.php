<?php
#App\GP247\Plugins\SandboxDemo\Support\SandboxGuard.php

namespace App\GP247\Plugins\SandboxDemo\Support;

use App\GP247\Plugins\SandboxDemo\Exceptions\SandboxWriteBlockedException;

/**
 * Core of the sandbox write-guard (ADR sandbox-demo_write-guard-layer, Layer A).
 *
 * Inspects every SQL statement passed through Connection::beforeExecuting and blocks
 * data-changing statements while the demo mode is active, so a logged-in operator can
 * browse the whole admin on GP247 v3 (Livewire) without persisting any change. The
 * decision is made on the SQL text only — no extra query is issued — so it is
 * transport-agnostic (Livewire, controller, or query-builder) and cheap.
 *
 * Scope is deny-by-default (ADR sandbox-demo_storefront-allowlist): the DB hook is
 * global, and a write is guarded whenever the current request is not on the storefront
 * (SandboxSurface), whoever the caller is — session, token or nobody. The storefront,
 * operator sign-in/out and console/queue keep writing.
 *
 * @aidlc-unit sandbox-demo-plugin
 * @aidlc-story US-sandbox-demo-block-db-writes
 * @aidlc-adr sandbox-demo_write-guard-layer
 * @aidlc-adr sandbox-demo_storefront-allowlist
 */
class SandboxGuard
{
    /** Data-changing verbs — candidates for blocking. */
    private const WRITE_VERBS = [
        'insert', 'update', 'delete', 'replace', 'truncate', 'drop', 'alter', 'create', 'rename', 'merge',
    ];

    /** Read/scan verbs — allowed unless the statement body writes (see classify()). */
    private const READ_VERBS = ['select', 'show', 'explain', 'describe', 'desc', 'with'];

    /** Transaction / session control verbs — never change data. */
    private const CONTROL_VERBS = ['set', 'start', 'begin', 'commit', 'rollback', 'savepoint', 'release', 'use', 'pragma', 'unlock'];

    /**
     * Re-entrancy flag: anything resolved while inspecting (config, translations) must not
     * re-enter the guard if it ever issues SQL; skip the guard while already inspecting.
     */
    private static bool $inspecting = false;

    /**
     * Whether demo mode is switched on.
     *
     * @return bool True when the sandbox enforces on restricted surfaces.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-db-writes
     */
    public static function isActive(): bool
    {
        return (bool) config('Plugins/SandboxDemo.SANDBOX_DEMO_ENABLED');
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
     * @aidlc-story US-sandbox-demo-allow-storefront-only
     */
    public static function inspect(string $query): void
    {
        if (self::$inspecting) {
            return;
        }

        self::$inspecting = true;
        try {
            // WHY switch first: normal sites (demo off) pay one config lookup per query.
            if (!self::isActive()) {
                return;
            }
            if (SandboxSurface::classify(request()) !== SandboxSurface::RESTRICTED) {
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
     * @return string One of "write", "read", "control" (control = transaction/session verbs, always allowed).
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
            // WHY: a CTE can end in a data change (WITH … UPDATE/DELETE on MySQL 8), and
            // SELECT … INTO OUTFILE/DUMPFILE writes a file on the database server.
            if ($verb === 'with' && preg_match('/\b(insert|update|delete|replace)\b/i', $query)) {
                return 'write';
            }
            if ($verb === 'select' && preg_match('/\binto\s+(outfile|dumpfile)\b/i', $query)) {
                return 'write';
            }

            return 'read';
        }
        if (in_array($verb, self::CONTROL_VERBS, true)) {
            return 'control';
        }

        // WHY fail-safe: an unknown verb (call, load, do, handler, lock, grant, optimize…)
        // may change data; treating it as a write means a new verb is blocked, not leaked.
        return 'write';
    }

    /**
     * Queue listener: refuse to enqueue a job from a restricted surface, because the worker
     * that runs it later has no HTTP route and is never guarded.
     *
     * @param object $event Illuminate\Queue\Events\JobQueueing.
     * @return void
     * @throws SandboxWriteBlockedException When a restricted request tries to queue work.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-keep-infra
     */
    public static function guardQueueing(object $event): void
    {
        if (self::isActive() && SandboxSurface::classify(request()) === SandboxSurface::RESTRICTED) {
            throw new SandboxWriteBlockedException();
        }
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
     * First SQL keyword, lowercased, skipping leading comments and parentheses.
     *
     * @param string $query Raw SQL statement.
     * @return string|null Leading verb, or null when none found.
     */
    private static function firstVerb(string $query): ?string
    {
        $sql = ltrim($query);

        // Strip leading comments (block, "-- " and "#" line comments) drivers or callers prepend.
        while (true) {
            if (strncmp($sql, '/*', 2) === 0) {
                $end = strpos($sql, '*/');
                if ($end === false) {
                    return null;
                }
                $sql = ltrim(substr($sql, $end + 2));
                continue;
            }
            if (strncmp($sql, '--', 2) === 0 || strncmp($sql, '#', 1) === 0) {
                $end = strpos($sql, "\n");
                if ($end === false) {
                    return null;
                }
                $sql = ltrim(substr($sql, $end + 1));
                continue;
            }
            break;
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
}
