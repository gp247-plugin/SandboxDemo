<?php
#App\GP247\Plugins\SandboxDemo\Exceptions\SandboxWriteBlockedException.php

namespace App\GP247\Plugins\SandboxDemo\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when the sandbox blocks a data-changing statement while demo mode is active.
 *
 * Renders as a friendly message rather than a raw stack trace: for Livewire requests it
 * emits the `gp247_admin_denied` JSON shape that core admin.js turns into a toast (so the
 * admin UI stays intact), a plain {error, msg} envelope for other AJAX, and a plain 403
 * body otherwise.
 *
 * @aidlc-unit sandbox-demo-plugin
 * @aidlc-story US-sandbox-demo-block-db-writes
 * @aidlc-adr sandbox-demo_write-guard-layer
 */
class SandboxWriteBlockedException extends RuntimeException
{
    /**
     * @param string|null $message Override message; defaults to the localized demo notice.
     */
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? self::defaultMessage());
    }

    /**
     * Report hook: a blocked demo action is expected behaviour, not an application error.
     *
     * WHY return true: Laravel treats a report() result other than false as "handled", so
     * nothing is written to the log. Without it every blocked click — which any visitor can
     * repeat at will — would append a full stack trace to laravel.log.
     *
     * @return bool Always true (handled, do not log).
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-db-writes
     */
    public function report(): bool
    {
        return true;
    }

    /**
     * Render the exception as an HTTP response.
     *
     * @param Request $request Current request.
     * @return \Symfony\Component\HttpFoundation\Response Tagged JSON for Livewire (friendly toast), plain JSON for other AJAX and any api/* caller, plain 403 body otherwise.
     *
     * @aidlc-unit sandbox-demo-plugin
     * @aidlc-story US-sandbox-demo-block-db-writes
     */
    public function render(Request $request)
    {
        $message = $this->getMessage();

        // WHY: Livewire's request hook in core admin.js only turns a 403 into a
        // friendly toast when the payload carries `gp247_admin_denied`; any other
        // shape is dumped as a raw JSON overlay. Mirror the core
        // AuthorizationException handler (CoreServiceProvider::registerAuthorizationExceptionRendering)
        // so a blocked demo write surfaces as a notice, not raw JSON.
        if ($request->hasHeader('X-Livewire')) {
            return response()->json([
                'gp247_admin_denied' => true,
                'message'            => $message,
            ], 403);
        }

        // Non-Livewire AJAX callers (e.g. jQuery endpoints) keep the plugin's
        // conventional {error, msg} envelope. WHY is('api/*'): the route guard stops API
        // and MCP calls before ForceJsonResponse runs, so a client that sent no Accept
        // header must still get JSON rather than an HTML/plain body.
        if ($request->ajax() || $request->wantsJson() || $request->is('api/*')) {
            return response()->json(['error' => 1, 'msg' => $message], 403);
        }

        return response($message, 403);
    }

    /**
     * Localized default message with an English fallback when translations are absent.
     *
     * @return string Human-readable demo-mode notice.
     */
    private static function defaultMessage(): string
    {
        $key = 'Plugins/SandboxDemo::lang.write_blocked';
        $message = trans($key);

        return $message === $key ? 'Demo mode: changes to system data are disabled.' : $message;
    }
}
