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
     // blocks every data-changing statement while demo mode is active — catches
     // Livewire, controller and query-builder writes alike (transport-agnostic on v3).
     \Illuminate\Support\Facades\DB::beforeExecuting(function ($query, $bindings, $connection) {
         \App\GP247\Plugins\SandboxDemo\Support\SandboxGuard::inspect($query);
     });

     app('router')->aliasMiddleware('sandbox-demo', \App\GP247\Plugins\SandboxDemo\Middleware\SandBoxMiddleware::class);

     // Ensure runtime Router groups receive middleware even if groups were already registered
     app('router')->pushMiddlewareToGroup('admin', 'sandbox-demo'); //admin
     app('router')->pushMiddlewareToGroup('api.extend', 'sandbox-demo'); //api
     app('router')->pushMiddlewareToGroup('pmo_partner', 'sandbox-demo'); // pmo247
     app('router')->pushMiddlewareToGroup('vendor', 'sandbox-demo'); // multi-vendor
     // For front, group may be registered later by FrontServiceProvider; we keep config above

 }