<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\Drip\Services\InvoiceMatchService;
use Platform\Drip\Services\InvoiceSyncService;
use Platform\Integrations\Exceptions\EasybillApiException;
use Platform\Integrations\Models\Integration;
use Platform\Integrations\Models\IntegrationConnection;

class SyncInvoicesCommand extends Command
{
    protected $signature = 'drip:sync-invoices
                                    {--team= : Specific team ID}
                                    {--no-match : Nur spiegeln, nicht abgleichen}';

    protected $description = 'Spiegelt easybill-Ausgangsrechnungen in Drip und gleicht sie gegen die Bank-Eingänge ab';

    public function handle(InvoiceSyncService $syncService, InvoiceMatchService $matchService): int
    {
        $teams = $this->resolveTeams($this->option('team'));

        if ($teams->isEmpty()) {
            $this->info('Keine Teams mit aktiver easybill-Verbindung gefunden.');
            return self::SUCCESS;
        }

        $this->info("{$teams->count()} Team(s) mit easybill-Verbindung.");

        foreach ($teams as $team) {
            $this->info("Team: {$team->name}");

            try {
                $sync = $syncService->syncForTeam($team);
                $this->info("   Rechnungen gespiegelt: {$sync['synced']} (übersprungen: {$sync['skipped']})");
            } catch (EasybillApiException $e) {
                $this->error("   Sync fehlgeschlagen: {$e->getMessage()}");
                continue;
            }

            if (!$this->option('no-match')) {
                $match = $matchService->matchForTeam($team);
                $this->info("   Abgeglichen: {$match['matched']} von {$match['checked']} offenen.");
            }
        }

        return self::SUCCESS;
    }

    protected function resolveTeams(?string $teamId)
    {
        if ($teamId) {
            $team = Team::find((int) $teamId);
            return $team ? collect([$team]) : collect();
        }

        $integration = Integration::where('key', 'easybill')->first();
        if (!$integration) {
            return collect();
        }

        $ownerIds = IntegrationConnection::where('integration_id', $integration->id)
            ->where('status', 'active')
            ->pluck('owner_user_id');

        if ($ownerIds->isEmpty()) {
            return collect();
        }

        return Team::whereHas('users', fn ($q) => $q->whereIn('users.id', $ownerIds))->get();
    }
}
