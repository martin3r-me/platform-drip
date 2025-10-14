<?php

namespace Platform\Drip;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use Illuminate\Support\Facades\Gate;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Policies\BankAccountPolicy;
use Platform\Drip\Policies\BankTransactionPolicy;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DripServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Falls in Zukunft Artisan Commands o.ä. nötig sind, hier rein
        
        // Keine Services in Drip vorhanden
    }

    public function boot(): void
    {
        // Policies
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(BankTransaction::class, BankTransactionPolicy::class);
        // Schritt 1: Config laden
        $this->mergeConfigFrom(__DIR__.'/../config/drip.php', 'drip');
        
        // Schritt 2: Existenzprüfung (config jetzt verfügbar)
        if (
            config()->has('drip.routing') &&
            config()->has('drip.navigation') &&
            Schema::hasTable('modules')
        ) {
            PlatformCore::registerModule([
                'key'        => 'drip',
                'title'      => 'Drip',
                'routing'    => config('drip.routing'),
                'guard'      => config('drip.guard'),
                'navigation' => config('drip.navigation'),
                'sidebar'    => config('drip.sidebar'),
            ]);
        }

        // Schritt 3: Wenn Modul registriert, Routes laden
        if (PlatformCore::getModule('drip')) {
            ModuleRouter::group('drip', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/guest.php');
            }, requireAuth: false);

            ModuleRouter::group('drip', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
        }

        // Schritt 4: Migrationen laden
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Schritt 5: Config veröffentlichen
        $this->publishes([
            __DIR__.'/../config/drip.php' => config_path('drip.php'),
        ], 'config');

        // Schritt 6: Views & Livewire
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'drip');
        $this->registerLivewireComponents();
    }

    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\Drip\\Livewire';
        $prefix = 'drip';

        if (!is_dir($basePath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class = $baseNamespace . '\\' . $classPath;

            if (!class_exists($class)) {
                continue;
            }

            // drip.dashboard aus drip + Dashboard.php -> dashboard
            $fileName = str_replace('.php', '', $relativePath);
            $aliasPath = Str::kebab($fileName);
            $alias = $prefix . '.' . $aliasPath;

            // Debug: Ausgabe der registrierten Komponente
            \Log::info("Registering Livewire component: {$alias} -> {$class}");
            
            try {
                Livewire::component($alias, $class);
            } catch (\Exception $e) {
                \Log::error("Failed to register Livewire component {$alias}: " . $e->getMessage());
            }
        }
    }
}
