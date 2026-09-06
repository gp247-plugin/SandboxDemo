<?php

namespace App\GP247\Plugins\SandboxDemo\Middleware;

use App\GP247\Plugins\SandboxDemo\Support\SandboxGuard;
use Illuminate\Http\Request;

/**
 * Layer B of the sandbox guard (ADR sandbox-demo_write-guard-layer): blocks
 * destructive Laravel File Manager operations by route name while demo mode is
 * active. Many LFM mutations (delete, rename, move, resize, crop, new folder) run as
 * GET, so the DB-level guard (Layer A) cannot see them — they touch the filesystem,
 * not the database. Reads (browse/list/download) always pass.
 *
 * @aidlc-unit sandbox-demo-plugin
 * @aidlc-story US-sandbox-demo-block-lfm-destructive
 * @aidlc-adr sandbox-demo_write-guard-layer
 */
class SandBoxMiddleware
{
    /**
     * Handle an incoming request, blocking destructive LFM routes while sandboxed.
     *
     * @param Request  $request Current request.
     * @param \Closure $next    Next pipeline stage.
     * @param mixed    ...$args Unused middleware arguments.
     * @return mixed Response.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-lfm-destructive
     */
    public function handle(Request $request, \Closure $next, ...$args)
    {
        if (SandboxGuard::isActive()
            && $request->route()
            && self::isDestructiveRouteName($request->route()->getName())
        ) {
            $message = self::message();

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'error' => 1,
                    'msg' => $message,
                    'detail' => ['method' => $request->method(), 'url' => $request->fullUrl()],
                ]);
            }

            abort(403, $message);
        }

        return $next($request);
    }

    /**
     * Whether a route name is a destructive LFM operation configured for blocking.
     *
     * @param string|null $name Route name (e.g. "unisharp.lfm.getDelete"); null is never destructive.
     * @return bool True when the route mutates files and must be blocked.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-lfm-destructive
     */
    public static function isDestructiveRouteName(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        return in_array($name, (array) config('Plugins/SandboxDemo.lfm_destructive_routes', []), true);
    }

    /**
     * Localized "file changes disabled" notice with an English fallback.
     *
     * @return string Human-readable demo-mode notice.
     */
    private static function message(): string
    {
        $key = 'Plugins/SandboxDemo::lang.lfm_blocked';
        $message = trans($key);

        return $message === $key ? 'Demo mode: file changes are disabled.' : $message;
    }
}
