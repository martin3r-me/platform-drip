<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\Requisition;
use Platform\Drip\Models\Institution;

class DebugRequisitionCreateCommand extends Command
{
    protected $signature = 'drip:debug-create {team_id}';
    protected $description = 'Debug: Testet Requisition, BankAccount und BankTransaction create() ohne GoCardless';

    public function handle(): int
    {
        $teamId = (int) $this->argument('team_id');
        $errors = 0;

        $this->info("=== Drip Create Debug für Team {$teamId} ===\n");

        // 1. Requisition
        $errors += $this->testRequisition($teamId);

        // 2. BankAccount
        $errors += $this->testBankAccount($teamId);

        // 3. BankTransaction (braucht ein BankAccount)
        $errors += $this->testBankTransaction($teamId);

        $this->newLine();
        if ($errors === 0) {
            $this->info('✓ Alle Tests bestanden - GoCardless-Flow sollte funktionieren.');
        } else {
            $this->error("✗ {$errors} Test(s) fehlgeschlagen.");
        }

        return $errors > 0 ? 1 : 0;
    }

    private function testRequisition(int $teamId): int
    {
        $this->info('--- 1. Requisition::create() ---');
        $reference = uniqid('debug_ref_');

        try {
            $institution = Institution::where('team_id', $teamId)->first()
                ?? Institution::first();

            $req = Requisition::create([
                'external_id' => 'debug_' . uniqid(),
                'reference' => $reference,
                'institution_id' => $institution?->id,
                'team_id' => $teamId,
                'status' => 'CR',
                'redirect' => 'https://example.com/callback',
            ]);

            $fromDb = Requisition::find($req->id);
            $hash = \Platform\Core\Support\FieldHasher::hmacSha256($reference, (string) $teamId);
            $hashMatch = $hash === $fromDb?->reference_hash;
            $lookupOk = Requisition::where('reference_hash', $hash)->exists();

            $this->info("  exists: {$req->exists}, id: {$req->id}");
            $this->info("  reference_hash: " . ($req->reference_hash ?? 'NULL'));
            $this->info("  hash match: " . ($hashMatch ? 'YES' : 'NO'));
            $this->info("  lookup by hash: " . ($lookupOk ? 'OK' : 'FAIL'));

            $req->forceDelete();
            $this->info("  → OK (cleaned up)\n");
            return 0;
        } catch (\Throwable $e) {
            $this->error("  FAILED: " . $e->getMessage());
            $this->error("  " . $e->getFile() . ":" . $e->getLine() . "\n");
            return 1;
        }
    }

    private function testBankAccount(int $teamId): int
    {
        $this->info('--- 2. BankAccount::create() ---');

        try {
            $account = BankAccount::create([
                'team_id' => $teamId,
                'external_id' => 'debug_acc_' . uniqid(),
                'name' => 'Debug Testkonto',
                'iban' => 'DE89370400440532013000',
                'bban' => '370400440532013000',
                'bic' => 'COBADEFFXXX',
                'currency' => 'EUR',
                'initial_balance' => '1234.56',
            ]);

            $fromDb = BankAccount::find($account->id);

            $this->info("  exists: {$account->exists}, id: {$account->id}");
            $this->info("  iban_hash: " . ($fromDb->iban_hash ?? 'NULL'));
            $this->info("  bban_hash: " . ($fromDb->bban_hash ?? 'NULL'));
            $this->info("  bic_hash: " . ($fromDb->bic_hash ?? 'NULL'));
            $this->info("  initial_balance_hash: " . ($fromDb->initial_balance_hash ?? 'NULL'));
            $this->info("  iban (decrypted): " . ($fromDb->iban ?? 'NULL'));

            $account->forceDelete();
            $this->info("  → OK (cleaned up)\n");
            return 0;
        } catch (\Throwable $e) {
            $this->error("  FAILED: " . $e->getMessage());
            $this->error("  " . $e->getFile() . ":" . $e->getLine() . "\n");
            return 1;
        }
    }

    private function testBankTransaction(int $teamId): int
    {
        $this->info('--- 3. BankTransaction::create() ---');

        // Temporäres Konto für FK
        $account = null;
        try {
            $account = BankAccount::create([
                'team_id' => $teamId,
                'external_id' => 'debug_acc_tx_' . uniqid(),
                'name' => 'Debug TX Testkonto',
                'currency' => 'EUR',
            ]);

            $tx = BankTransaction::create([
                'transaction_id' => 'debug_tx_' . uniqid(),
                'bank_account_id' => $account->id,
                'team_id' => $teamId,
                'booked_at' => now()->toDateString(),
                'booking_date' => now()->toDateString(),
                'amount' => '-42.50',
                'currency' => 'EUR',
                'direction' => 'debit',
                'counterparty_name' => 'Test GmbH',
                'counterparty_iban' => 'DE02120300000000202051',
                'debtor_name' => 'Max Mustermann',
                'creditor_name' => 'Test GmbH',
                'debtor_account_iban' => 'DE89370400440532013000',
                'creditor_account_iban' => 'DE02120300000000202051',
                'debtor_agent' => 'COBADEFFXXX',
                'creditor_agent' => 'BYLADEM1001',
                'reference' => 'RE-2025-001 Testbuchung',
                'remittance_information' => 'Zahlung für Rechnung RE-2025-001',
                'additional_information' => 'Debug test',
            ]);

            $fromDb = BankTransaction::find($tx->id);

            $this->info("  exists: {$tx->exists}, id: {$tx->id}");
            $this->info("  amount_hash: " . ($fromDb->amount_hash ?? 'NULL'));
            $this->info("  counterparty_name (decrypted): " . ($fromDb->counterparty_name ?? 'NULL'));
            $this->info("  debtor_name_hash: " . ($fromDb->debtor_name_hash ?? 'NULL'));
            $this->info("  creditor_account_iban_hash: " . ($fromDb->creditor_account_iban_hash ?? 'NULL'));
            $this->info("  reference_hash: " . ($fromDb->reference_hash ?? 'NULL'));
            $this->info("  additional_information_hash: " . ($fromDb->additional_information_hash ?? 'NULL'));

            $tx->forceDelete();
            $account->forceDelete();
            $this->info("  → OK (cleaned up)\n");
            return 0;
        } catch (\Throwable $e) {
            $this->error("  FAILED: " . $e->getMessage());
            $this->error("  " . $e->getFile() . ":" . $e->getLine() . "\n");
            // Cleanup bei Fehler
            if ($account?->exists) {
                $account->forceDelete();
            }
            return 1;
        }
    }
}
