<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Services\MossSyncService;

class BackfillDisregardedCommand extends Command
{
    protected $signature = 'drip:backfill-disregarded {--team= : Nur ein Team}';

    protected $description = 'Setzt is_disregarded für bestehende MOSS-Transaktionen anhand des gespeicherten metadata (abgelehnte/gelöschte Kartenzahlungen).';

    public function handle(): int
    {
        $query = BankTransaction::query()->where('transaction_id', 'like', 'moss_%');
        if ($team = $this->option('team')) {
            $query->where('team_id', (int) $team);
        }

        $checked = 0;
        $updated = 0;

        $query->chunkById(500, function ($txs) use (&$checked, &$updated) {
            foreach ($txs as $tx) {
                $checked++;
                $meta = is_array($tx->metadata) ? $tx->metadata : [];
                $disregarded = MossSyncService::isDisregardedExpense($meta);

                if ((bool) $tx->is_disregarded !== $disregarded) {
                    // Direktes Update — keine Model-Events/Encryption anfassen.
                    BankTransaction::whereKey($tx->id)->update(['is_disregarded' => $disregarded]);
                    $updated++;
                }
            }
        });

        $this->info("MOSS-Transaktionen geprüft: {$checked}, als 'nicht berücksichtigt' markiert/aktualisiert: {$updated}.");

        return self::SUCCESS;
    }
}
