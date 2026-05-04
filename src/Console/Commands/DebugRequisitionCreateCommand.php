<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Models\Requisition;
use Platform\Drip\Models\Institution;

class DebugRequisitionCreateCommand extends Command
{
    protected $signature = 'drip:debug-requisition-create {team_id}';
    protected $description = 'Debug: Testet Requisition::create() ohne GoCardless';

    public function handle(): int
    {
        $teamId = (int) $this->argument('team_id');
        $reference = uniqid('debug_ref_');

        $this->info("Testing Requisition::create() for team {$teamId}");
        $this->info("Reference: {$reference}");

        $institution = Institution::where('team_id', $teamId)->first();
        $this->info("Institution: " . ($institution ? "{$institution->id} ({$institution->name})" : 'NONE'));

        try {
            $req = Requisition::create([
                'external_id' => 'debug_' . uniqid(),
                'reference' => $reference,
                'institution_id' => $institution?->id,
                'team_id' => $teamId,
                'status' => 'CR',
                'redirect' => 'https://example.com/callback',
            ]);

            $this->info("--- Result ---");
            $this->info("exists: " . ($req->exists ? 'true' : 'false'));
            $this->info("id: " . ($req->id ?? 'NULL'));
            $this->info("uuid: " . ($req->uuid ?? 'NULL'));
            $this->info("reference (encrypted?): " . ($req->getRawOriginal('reference') ?? 'NULL'));
            $this->info("reference_hash: " . ($req->reference_hash ?? 'NULL'));
            $this->info("team_id: " . ($req->team_id ?? 'NULL'));

            // Verify in DB
            $fromDb = Requisition::find($req->id);
            if ($fromDb) {
                $this->info("--- DB Verification ---");
                $this->info("Found in DB: YES");
                $this->info("DB reference_hash: " . ($fromDb->reference_hash ?? 'NULL'));
                $this->info("DB reference (decrypted): " . ($fromDb->reference ?? 'NULL'));

                // Hash lookup test
                $hash = \Platform\Core\Support\FieldHasher::hmacSha256($reference, (string) $teamId);
                $this->info("--- Hash Lookup Test ---");
                $this->info("Computed hash: {$hash}");
                $this->info("Stored hash:   " . ($fromDb->reference_hash ?? 'NULL'));
                $this->info("Match: " . ($hash === $fromDb->reference_hash ? 'YES' : 'NO'));

                $found = Requisition::where('reference_hash', $hash)->first();
                $this->info("Lookup by hash: " . ($found ? "Found (id={$found->id})" : 'NOT FOUND'));
            } else {
                $this->error("Found in DB: NO - Create returned id={$req->id} but row not in DB!");
            }

            // Cleanup
            $req->forceDelete();
            $this->info("Cleaned up test requisition.");

        } catch (\Throwable $e) {
            $this->error("CREATE FAILED: " . $e->getMessage());
            $this->error("File: " . $e->getFile() . ":" . $e->getLine());
            $this->error("Trace:\n" . $e->getTraceAsString());
            return 1;
        }

        return 0;
    }
}
