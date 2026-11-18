<?php
#App\GP247\Plugins\SandboxDemo\Support\SandboxRouteGuard.php

namespace App\GP247\Plugins\SandboxDemo\Support;

use App\GP247\Plugins\SandboxDemo\Exceptions\SandboxWriteBlockedException;
use Illuminate\Routing\Events\RouteMatched;

/**
 * Layer B of the sandbox (ADR sandbox-demo_storefront-allowlist): a global route guard on
 * RouteMatched, so it covers every route whatever its middleware group (MCP has none).
 *
 * On a restricted surface it stops, before any route middleware or controller runs:
 * every request that is not GET/HEAD/OPTIONS (except Livewire's own endpoints, whose
 * writes the DB guard handles), and the GET routes listed as destructive. File and code
 * operations the DB guard cannot see — extension import/install, backup, uploads — never start.
 *
 * @aidlc-unit sandbox-demo-plugin
 * @aidlc-story US-sandbox-demo-block-file-code-ops
 * @aidlc-story US-sandbox-demo-block-api-mcp
 * @aidlc-story US-sandbox-demo-block-lfm-destructive
 * @aidlc-adr sandbox-demo_storefront-allowlist
 */
class SandboxRouteGuard
{
    /** Methods that never change state by HTTP contract. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * RouteMatched listener.
     *
     * @param RouteMatched $event Matched route and request.
     * @return void
     * @throws SandboxWriteBlockedException When the request must not proceed in demo mode.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-file-code-ops
     */
    public static function handle(RouteMatched $event): void
    {
        if (!SandboxGuard::isActive()) {
            return;
        }

        if (SandboxSurface::classify($event->request) !== SandboxSurface::RESTRICTED) {
            return;
        }

        if (self::isBlockedRouteName($event->route->getName())) {
            throw new SandboxWriteBlockedException();
        }

        if (in_array(strtoupper($event->request->getMethod()), self::SAFE_METHODS, true)) {
            return;
        }

        if (SandboxSurface::isLivewireEndpoint($event->route)) {
            if (self::hasBlockedLivewireActivity(SandboxSurface::livewireComponents($event->request))) {
                throw new SandboxWriteBlockedException();
            }

            return;
        }

        throw new SandboxWriteBlockedException();
    }

    /**
     * Whether a restricted Livewire update asks for work the DB guard would see too late.
     *
     * Some admin actions touch files or the network before their first SQL statement
     * (deleting a backup archive, probing a remote host, minting an upload URL), so the
     * DB guard alone cannot stop them. Two rules close that gap:
     * - a call to a listed method (Livewire's upload built-ins by default) is refused on
     *   any component;
     * - a component whose class sits in a listed namespace (the backup module by default)
     *   accepts no property update and no call except the listed read-only ones.
     *
     * @param array<int, array{name: string, class: string|null, calls: string[], updates: bool}> $components
     * @return bool True when the request must be refused.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-file-code-ops
     */
    public static function hasBlockedLivewireActivity(array $components): bool
    {
        $blockedMethods = (array) config('Plugins/SandboxDemo.blocked_livewire_methods', []);
        $readOnlyCalls = (array) config('Plugins/SandboxDemo.readonly_livewire_calls', []);
        $blockedNamespaces = array_filter(array_map(
            static fn ($ns): string => ltrim((string) $ns, '\\'),
            (array) config('Plugins/SandboxDemo.blocked_livewire_namespaces', [])
        ));

        foreach ($components as $component) {
            if (array_intersect($component['calls'], $blockedMethods) !== []) {
                return true;
            }

            $class = (string) ($component['class'] ?? '');
            $inBlockedNamespace = $class !== '' && array_filter(
                $blockedNamespaces,
                static fn (string $ns): bool => str_starts_with($class, $ns)
            ) !== [];

            if ($inBlockedNamespace
                && ($component['updates'] || array_diff($component['calls'], $readOnlyCalls) !== [])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a route name is listed as destructive even for GET (LFM, backup download).
     *
     * @param string|null $name Route name; null is never blocked by name.
     * @return bool True when the route is in `blocked_get_routes`.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-lfm-destructive
     */
    public static function isBlockedRouteName(?string $name): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        return in_array($name, (array) config('Plugins/SandboxDemo.blocked_get_routes', []), true);
    }
}
