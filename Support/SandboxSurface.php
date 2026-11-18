<?php
#App\GP247\Plugins\SandboxDemo\Support\SandboxSurface.php

namespace App\GP247\Plugins\SandboxDemo\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Throwable;
use WeakMap;

/**
 * Classifies a request into the surface the sandbox reasons about
 * (ADR sandbox-demo_storefront-allowlist): deny by default, only the storefront writes.
 *
 * The decision uses server-side or signed data only — the matched route (name and
 * middleware groups), the path, and for a Livewire update the `memo.path` of every
 * component snapshot after its HMAC checksum is verified. It never trusts Referer,
 * Origin or X-Livewire, which the caller controls.
 *
 * @aidlc-unit sandbox-demo-plugin
 * @aidlc-story US-sandbox-demo-allow-storefront-only
 * @aidlc-adr sandbox-demo_storefront-allowlist
 */
class SandboxSurface
{
    /** No HTTP route: console command, queue worker, scheduler. Never guarded. */
    public const BACKGROUND = 'background';

    /** Operator sign-in / sign-out routes. Allowed so the demo can be entered. */
    public const OPERATOR_AUTH = 'operator-auth';

    /** Storefront page, customer REST API, or Livewire mounted on a storefront page. */
    public const STOREFRONT = 'storefront';

    /** Everything else: admin, admin API, MCP, vendor/partner backends, unknown routes. */
    public const RESTRICTED = 'restricted';

    /** Classification memo per request object (no static leak across Octane requests). */
    private static ?WeakMap $memo = null;

    /**
     * Classify a request.
     *
     * @param Request|null $request Current request, or null in a context without one.
     * @return string One of the class constants.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-allow-storefront-only
     * @aidlc-story US-sandbox-demo-keep-infra
     */
    public static function classify(?Request $request): string
    {
        $route = $request?->route();

        if (!$route instanceof Route) {
            // WHY: a console process has no matched route (artisan, queue:work, schedule:run);
            // an HTTP request without one has not been routed yet — fail safe, and do not memo
            // so the real classification happens once the router has matched.
            // WHY the Octane check: Octane workers run under the CLI SAPI, so runningInConsole()
            // is true for their HTTP requests too — those must stay fail-safe.
            return app()->runningInConsole() && !self::runningOctane() ? self::BACKGROUND : self::RESTRICTED;
        }

        self::$memo ??= new WeakMap();
        if (isset(self::$memo[$request])) {
            return self::$memo[$request];
        }

        return self::$memo[$request] = self::classifyRouted($request, $route);
    }

    /**
     * Whether a matched route is Livewire's update or upload endpoint. Those endpoints carry
     * both reads and writes, so the route guard lets them through and the DB guard decides.
     *
     * @param Route $route Matched route.
     * @return bool True for the Livewire update/upload endpoint.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-file-code-ops
     */
    public static function isLivewireEndpoint(Route $route): bool
    {
        $name = (string) $route->getName();

        // WHY suffix: a site may register a custom update route; Livewire then prefixes the name.
        return str_ends_with($name, 'livewire.update') || str_ends_with($name, 'livewire.upload-file');
    }

    /**
     * The components a Livewire update targets, with the methods it calls on each.
     *
     * Used by the route guard to stop side effects that happen before any SQL (file,
     * network). The data is unverified here; Livewire rejects a tampered snapshot before
     * calling anything, so a forged name can only make the request fail, never pass.
     *
     * @param Request $request Livewire update request.
     * @return array<int, array{name: string, class: string|null, calls: string[], updates: bool}>
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-file-code-ops
     */
    public static function livewireComponents(Request $request): array
    {
        $components = $request->input('components');
        if (!is_array($components)) {
            return [];
        }

        $result = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
            $name = is_array($snapshot) ? (string) ($snapshot['memo']['name'] ?? '') : '';
            $calls = [];
            foreach ((array) ($component['calls'] ?? []) as $call) {
                $calls[] = is_array($call) ? (string) ($call['method'] ?? '') : '';
            }
            $result[] = [
                'name'    => $name,
                'class'   => self::componentClass($name),
                'calls'   => $calls,
                'updates' => !empty($component['updates']),
            ];
        }

        return $result;
    }

    /**
     * Resolve a Livewire component name to its class, across Livewire 3 and 4.
     *
     * @param string $name Component name from the snapshot memo.
     * @return string|null Fully-qualified class, or null when it cannot be resolved.
     */
    private static function componentClass(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        try {
            if (app()->bound('livewire.finder')) {
                $class = app('livewire.finder')->resolveClassComponentClassName($name);
            } elseif (class_exists(\Livewire\Mechanisms\ComponentRegistry::class)) {
                $class = app(\Livewire\Mechanisms\ComponentRegistry::class)->getClass($name);
            } else {
                $class = null;
            }
        } catch (Throwable) {
            $class = null;
        }

        return is_string($class) && $class !== '' ? ltrim($class, '\\') : null;
    }

    /**
     * Whether the process is a Laravel Octane worker serving HTTP.
     *
     * @return bool True under Octane.
     */
    private static function runningOctane(): bool
    {
        return !empty($_SERVER['LARAVEL_OCTANE']) || !empty($_ENV['LARAVEL_OCTANE']) || (bool) getenv('LARAVEL_OCTANE');
    }

    /**
     * Classify a request whose route is already matched.
     *
     * @param Request $request Current request.
     * @param Route   $route   Matched route.
     * @return string One of the class constants.
     */
    private static function classifyRouted(Request $request, Route $route): string
    {
        if (in_array((string) $route->getName(), self::configList('operator_auth_routes'), true)) {
            return self::OPERATOR_AUTH;
        }

        if (self::isStorefrontRoute($route, $request->path())) {
            return self::STOREFRONT;
        }

        if (str_ends_with((string) $route->getName(), 'livewire.update')) {
            return self::classifyLivewireUpdate($request);
        }

        return self::RESTRICTED;
    }

    /**
     * A Livewire update is storefront only when every component in it was mounted on a
     * storefront page, read from its signed snapshot.
     *
     * @param Request $request Livewire update request.
     * @return string STOREFRONT or RESTRICTED.
     */
    private static function classifyLivewireUpdate(Request $request): string
    {
        $components = $request->input('components');
        if (!is_array($components) || $components === []) {
            return self::RESTRICTED;
        }

        foreach ($components as $component) {
            $snapshot = is_array($component) ? json_decode((string) ($component['snapshot'] ?? ''), true) : null;
            if (!is_array($snapshot) || !self::hasValidChecksum($snapshot)) {
                return self::RESTRICTED;
            }

            $path = $snapshot['memo']['path'] ?? null;
            $method = $snapshot['memo']['method'] ?? 'GET';
            if (!is_string($path) || !is_string($method)) {
                return self::RESTRICTED;
            }

            $hostRoute = self::matchRoute($request, $path, $method);
            if ($hostRoute === null || !self::isStorefrontRoute($hostRoute, ltrim($path, '/'))) {
                return self::RESTRICTED;
            }
        }

        return self::STOREFRONT;
    }

    /**
     * Verify a snapshot checksum the way Livewire does, without Livewire's failure counter.
     *
     * @param array<string, mixed> $snapshot Decoded snapshot including its checksum.
     * @return bool True when the checksum matches the snapshot content.
     */
    private static function hasValidChecksum(array $snapshot): bool
    {
        $checksum = $snapshot['checksum'] ?? null;
        if (!is_string($checksum) || $checksum === '') {
            return false;
        }
        unset($snapshot['checksum']);

        try {
            // WHY not Checksum::verify(): it records a failure against the client IP and
            // throws; Livewire will run its own verification right after, so counting here
            // would double-penalise the caller.
            return hash_equals(Checksum::generate($snapshot), $checksum);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Match the route a component's host page was served from (same host as this request).
     *
     * @param Request $request Current request (scheme + host reused for domain routes).
     * @param string  $path    Host page path from the snapshot.
     * @param string  $method  Host page method from the snapshot.
     * @return Route|null The matched route, or null when nothing matches.
     */
    private static function matchRoute(Request $request, string $path, string $method): ?Route
    {
        try {
            $probe = Request::create($request->getSchemeAndHttpHost() . '/' . ltrim($path, '/'), strtoupper($method));

            return app('router')->getRoutes()->match($probe);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a route belongs to the storefront allowlist.
     *
     * @param Route  $route Matched route.
     * @param string $path  Request path without a leading slash.
     * @return bool True for a storefront group route, a customer API path or a listed route name.
     */
    private static function isStorefrontRoute(Route $route, string $path): bool
    {
        // WHY middleware() and not gatherMiddleware(): the latter resolves the controller
        // instance to read its own middleware, which may run constructor side effects.
        if (array_intersect((array) $route->middleware(), self::configList('storefront_route_groups')) !== []) {
            return true;
        }

        if (in_array((string) $route->getName(), self::configList('storefront_route_names'), true)) {
            return true;
        }

        $path = ltrim($path, '/');
        foreach (self::configList('storefront_path_prefixes') as $prefix) {
            $prefix = trim($prefix, '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read a list-valued plugin config key as strings.
     *
     * @param string $key Key under Plugins/SandboxDemo.
     * @return string[] Configured values.
     */
    private static function configList(string $key): array
    {
        return array_values(array_filter(
            array_map('strval', (array) config('Plugins/SandboxDemo.' . $key, [])),
            static fn (string $value): bool => $value !== ''
        ));
    }
}
