<?php
/**
 * Provides everything needed for the Extension
 */

 $config = file_get_contents(__DIR__.'/gp247.json');
 $config = json_decode($config, true);
 $extensionPath = $config['configGroup'].'/'.$config['configKey'];

 $this->loadTranslationsFrom(__DIR__.'/Lang', $extensionPath);

 if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {

     $this->loadViewsFrom(__DIR__.'/Views', $extensionPath);

     if (file_exists(__DIR__.'/config.php')) {
         $this->mergeConfigFrom(__DIR__.'/config.php', $extensionPath);
     }

     if (file_exists(__DIR__.'/function.php')) {
         require_once __DIR__.'/function.php';
     }

     // Layer A (ADR sandbox-demo_write-guard-layer): a single DB choke point that
     // blocks every data-changing statement on a restricted (non-storefront) surface
     // while demo mode is active — Livewire, controller, query-builder, token APIs alike.
     // WHY every connection: DB::beforeExecuting only reaches the default connection, so
     // hook the ones already open and every connection opened later (other names, purge).
     $sandboxHook = static function ($query): void {
         \App\GP247\Plugins\SandboxDemo\Support\SandboxGuard::inspect($query);
     };
     foreach (app('db')->getConnections() as $sandboxConnection) {
         $sandboxConnection->beforeExecuting($sandboxHook);
     }
     \Illuminate\Support\Facades\Event::listen(
         \Illuminate\Database\Events\ConnectionEstablished::class,
         static function ($event) use ($sandboxHook): void {
             $event->connection->beforeExecuting($sandboxHook);
         }
     );

     // A restricted request must not hand work to a queue worker, which is never guarded.
     \Illuminate\Support\Facades\Event::listen(
         \Illuminate\Queue\Events\JobQueueing::class,
         [\App\GP247\Plugins\SandboxDemo\Support\SandboxGuard::class, 'guardQueueing']
     );

     // WHY: Livewire stores temporary uploads on the default disk; on a demo whose default
     // disk is public, an uploaded file would be web-reachable. Keep them private.
     if (config('Plugins/SandboxDemo.SANDBOX_DEMO_ENABLED') && config('filesystems.disks.local')) {
         config(['livewire.temporary_file_upload.disk' => 'local']);
     }

     // Layer B (ADR sandbox-demo_storefront-allowlist): a global route guard. RouteMatched
     // fires for every route — including ones outside any middleware group, such as MCP —
     // before route middleware and controllers, so file and code operations never start.
     \Illuminate\Support\Facades\Event::listen(
         \Illuminate\Routing\Events\RouteMatched::class,
         [\App\GP247\Plugins\SandboxDemo\Support\SandboxRouteGuard::class, 'handle']
     );

 }
