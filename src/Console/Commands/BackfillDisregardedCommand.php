<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Services\MossSyncService;

class BackfillDisregardedCommand extends Command
{
    protected $signature = 'drip:backfill-disregarded {--team= : Nur ein Team}';

    protected $description = 'Bewertet is_disregarded für bestehende MOSS-Transaktionen anhand des gespeicherten metadata neu (abgelehnte/gelöschte Kartenzahlungen).';

    public function handle(MossSyncService $sync): int
    {
        if ($team = $this->option('team')) {
            $teamIds = [(int) $team];
        } else {
            $teamIds = BankTransaction::where('transaction_id', 'like', 'moss_%')
                ->distinct()
                ->pluck('team_id')
                ->all();
        }

        $total = 0;
        foreach ($teamIds as $teamId) {
            $total += $sync->backfillDisregarded((int) $teamId);
        }

        $this->info("Aktualisiert: {$total} MOSS-Transaktion(en) über " . count($teamIds) . ' Team(s).');

        return self::SUCCESS;
    }
}
