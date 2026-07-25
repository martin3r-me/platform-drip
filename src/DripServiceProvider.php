<?php

namespace Platform\Drip;

use Illuminate\Console\Scheduling\Schedule;
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
use Platform\Drip\Observers\BankAccountObserver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DripServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            \Platform\Drip\Services\CashflowSignalRegistry::class,
            fn () => new \Platform\Drip\Services\CashflowSignalRegistry()
        );
    }

    public function boot(): void
    {
        // Policies
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(BankTransaction::class, BankTransactionPolicy::class);

        // MorphMap for Organization EntityLink integration
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
            'drip_bank_account_group' => \Platform\Drip\Models\BankAccountGroup::class,
            'drip_bank_transaction' => BankTransaction::class,
        ]);
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
                'group'      => 'finance',
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
            __DIR__.'/../config/services.php' => config_path('services.php'),
        ], 'config');
        
        // Services config mergen
        $this->mergeConfigFrom(__DIR__.'/../config/services.php', 'services');

        // Schritt 6: Views & Livewire
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'drip');
        $this->registerLivewireComponents();
        // Observer
        BankAccount::observe(BankAccountObserver::class);
        
        // EntityLinkProvider for Organization snapshots
        try {
            resolve(\Platform\Organization\Services\EntityLinkRegistry::class)
                ->register(new \Platform\Drip\Organization\DripEntityLinkProvider());
        } catch (\Throwable $e) {
            // Organization-Modul nicht geladen
        }

        // Schritt 7: Commands
        $this->registerCommands();

        // Schritt 8: MCP Tools
        $this->registerTools();

        // Schritt 9: Scheduling
        $this->registerSchedule();
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

    protected function registerTools(): void
    {
        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);

            $registry->register(new \Platform\Drip\Tools\DripOverviewTool());
            $registry->register(new \Platform\Drip\Tools\ListRequisitionsTool());
            $registry->register(new \Platform\Drip\Tools\ListBankAccountsTool());
            $registry->register(new \Platform\Drip\Tools\ListBankTransactionsTool());
            $registry->register(new \Platform\Drip\Tools\ListInstitutionsTool());
            $registry->register(new \Platform\Drip\Tools\RawLogsTool());
            $registry->register(new \Platform\Drip\Tools\CategoriesToolCrud());
            $registry->register(new \Platform\Drip\Tools\RulesToolCrud());
            // Budget eingemottet (2026-07): Tool deregistriert bis Forecast-Modul. Datei/Models bleiben erhalten.
            // $registry->register(new \Platform\Drip\Tools\BudgetItemsToolCrud());
            $registry->register(new \Platform\Drip\Tools\CashflowAnalyticsTool());
            $registry->register(new \Platform\Drip\Tools\DripTeamSettingsToolCrud());
        } catch (\Throwable $e) {
            \Log::warning('Drip: Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }

    protected function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // MOSS-Sync: nachts um 02:00 (vor GoCardless, damit Analytics beide Quellen sehen)
            $schedule->command('drip:sync-moss')
                ->dailyAt('02:00')
                ->withoutOverlapping()
                ->onOneServer();

            // Bank-Sync: morgens 03:00 (Nacht-Buchungen) + nachmittags 14:00 (Tages-Buchungen)
            $schedule->command('drip:update-bank-data --skip-details')
                ->twiceDaily(3, 14)
                ->withoutOverlapping()
                ->onOneServer();

            // Monatliches Cleanup abgelaufener Requisitions (letzter Tag 03:30 Uhr)
            $schedule->command('drip:update-bank-data --cleanup')
                ->lastDayOfMonth('03:30')
                ->withoutOverlapping()
                ->onOneServer();

            // Cashflow-Signals: 1x täglich um 04:00 Uhr
            $schedule->command('drip:sync-signals')
                ->dailyAt('04:00')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Platform\Drip\Console\Commands\UpdateBankDataCommand::class,
                \Platform\Drip\Console\Commands\NormalizeTransactionsCommand::class,
                \Platform\Drip\Console\Commands\BuildGroupMetricsCommand::class,
                \Platform\Drip\Console\Commands\DebugRequisitionCreateCommand::class,
                \Platform\Drip\Console\Commands\DebugTransactionCommand::class,
                \Platform\Drip\Console\Commands\RepairTransactionIbansCommand::class,
                \Platform\Drip\Console\Commands\AnalyzeRawLogsCommand::class,
                \Platform\Drip\Console\Commands\DeduplicateTransactionsCommand::class,
                \Platform\Drip\Console\Commands\WipeTransactionsCommand::class,
                \Platform\Drip\Console\Commands\DetectRecurringBudgetsCommand::class,
                \Platform\Drip\Console\Commands\GenerateBudgetPeriodsCommand::class,
                \Platform\Drip\Console\Commands\BuildCashflowSnapshotsCommand::class,
                \Platform\Drip\Console\Commands\SyncCashflowSignalsCommand::class,
                \Platform\Drip\Console\Commands\SyncMossDataCommand::class,
            ]);
        }
    }
}
