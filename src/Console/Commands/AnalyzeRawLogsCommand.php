<?php

namespace Platform\Drip\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AnalyzeRawLogsCommand extends Command
{
    protected $signature = 'drip:analyze-raw-logs
                                    {file? : Path to specific JSON log file}
                                    {--latest : Use the most recent log file}
                                    {--list : List available log files}';

    protected $description = 'Analyze raw GoCardless transaction log files for field coverage and data quality';

    public function handle(): int
    {
        $logDir = storage_path('logs');

        if ($this->option('list')) {
            return $this->listLogFiles($logDir);
        }

        $file = $this->argument('file');
        if (!$file && $this->option('latest')) {
            $file = $this->findLatestLogFile($logDir);
        }

        if (!$file) {
            $this->error('Specify a file path, use --latest, or --list to see available files.');
            return 1;
        }

        if (!File::exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $raw = json_decode(File::get($file), true);
        if (!$raw) {
            $this->error('Could not parse JSON from file.');
            return 1;
        }

        $this->info("Analyzing: {$file}");
        $this->newLine();

        $allTransactions = [];
        foreach ($raw as $accountId => $transactions) {
            $count = count($transactions);
            $this->info("Account: {$accountId} — {$count} transactions");
            foreach ($transactions as $tx) {
                $tx['_accountId'] = $accountId;
                $allTransactions[] = $tx;
            }
        }

        if (empty($allTransactions)) {
            $this->warn('No transactions found in log file.');
            return 0;
        }

        $this->newLine();
        $this->analyzeFieldCoverage($allTransactions);
        $this->newLine();
        $this->analyzeAmountDirections($allTransactions);
        $this->newLine();
        $this->analyzeNames($allTransactions);
        $this->newLine();
        $this->analyzeIbans($allTransactions);
        $this->newLine();
        $this->analyzeAdditionalInformation($allTransactions);
        $this->newLine();
        $this->analyzeRemittanceInformation($allTransactions);

        return 0;
    }

    protected function listLogFiles(string $logDir): int
    {
        $files = glob($logDir . '/gocardless-raw-*.json');
        if (empty($files)) {
            $this->warn('No GoCardless raw log files found in ' . $logDir);
            return 0;
        }

        rsort($files);
        $this->info('Available log files:');
        foreach ($files as $f) {
            $size = round(filesize($f) / 1024, 1);
            $this->line("  {$f} ({$size} KB)");
        }

        return 0;
    }

    protected function findLatestLogFile(string $logDir): ?string
    {
        $files = glob($logDir . '/gocardless-raw-*.json');
        if (empty($files)) {
            $this->error('No GoCardless raw log files found.');
            return null;
        }

        rsort($files);
        return $files[0];
    }

    protected function analyzeFieldCoverage(array $transactions): void
    {
        $this->info('=== FIELD COVERAGE ===');

        $fields = [
            'transactionId', 'internalTransactionId', 'entryReference',
            'bookingDate', 'valueDate',
            'transactionAmount',
            'debtorName', 'debtorAccount',
            'creditorName', 'creditorAccount',
            'remittanceInformationUnstructured', 'remittanceInformationUnstructuredArray',
            'remittanceInformationStructured', 'remittanceInformationStructuredArray',
            'additionalInformation', 'additionalInformationStructured',
            'bankTransactionCode', 'proprietaryBankTransactionCode',
            'purposeCode', 'endToEndId', 'mandateId',
            'merchantCategoryCode', 'creditorId',
        ];

        $total = count($transactions);
        $rows = [];

        foreach ($fields as $field) {
            $present = 0;
            foreach ($transactions as $tx) {
                if ($this->fieldPresent($tx, $field)) {
                    $present++;
                }
            }
            $pct = round($present / $total * 100);
            $rows[] = [$field, "{$present}/{$total}", "{$pct}%"];
        }

        $this->table(['Field', 'Present', '%'], $rows);
    }

    protected function analyzeAmountDirections(array $transactions): void
    {
        $this->info('=== AMOUNT DIRECTION ANALYSIS ===');

        $credits = 0;
        $debits = 0;
        $creditSum = 0;
        $debitSum = 0;

        foreach ($transactions as $tx) {
            $amount = (float) ($tx['transactionAmount']['amount'] ?? 0);
            if ($amount >= 0) {
                $credits++;
                $creditSum += $amount;
            } else {
                $debits++;
                $debitSum += abs($amount);
            }
        }

        $this->table(['Direction', 'Count', 'Sum'], [
            ['Credit (incoming)', $credits, number_format($creditSum, 2) . ' EUR'],
            ['Debit (outgoing)', $debits, number_format($debitSum, 2) . ' EUR'],
            ['Total', count($transactions), ''],
        ]);
    }

    protected function analyzeNames(array $transactions): void
    {
        $this->info('=== NAME ANALYSIS ===');

        $debtorNames = [];
        $creditorNames = [];
        $debitWithCreditorName = 0;
        $creditWithDebtorName = 0;
        $total = count($transactions);

        foreach ($transactions as $tx) {
            $amount = (float) ($tx['transactionAmount']['amount'] ?? 0);
            $isDebit = $amount < 0;

            $dn = $tx['debtorName'] ?? null;
            $cn = $tx['creditorName'] ?? null;

            if ($dn) {
                $debtorNames[$dn] = ($debtorNames[$dn] ?? 0) + 1;
            }
            if ($cn) {
                $creditorNames[$cn] = ($creditorNames[$cn] ?? 0) + 1;
            }

            if ($isDebit && $cn) {
                $debitWithCreditorName++;
            }
            if (!$isDebit && $dn) {
                $creditWithDebtorName++;
            }
        }

        // Most frequent names
        arsort($debtorNames);
        arsort($creditorNames);

        $this->line("Debits with creditorName (counterparty for debits): {$debitWithCreditorName}");
        $this->line("Credits with debtorName (counterparty for credits): {$creditWithDebtorName}");
        $this->newLine();

        $this->line('Top debtorName values:');
        $rows = [];
        foreach (array_slice($debtorNames, 0, 10, true) as $name => $count) {
            $rows[] = [$name, $count];
        }
        $this->table(['debtorName', 'Count'], $rows);

        $this->line('Top creditorName values:');
        $rows = [];
        foreach (array_slice($creditorNames, 0, 10, true) as $name => $count) {
            $rows[] = [$name, $count];
        }
        $this->table(['creditorName', 'Count'], $rows);

        // Identify likely own company name (most frequent debtorName for debits)
        $debitDebtorNames = [];
        foreach ($transactions as $tx) {
            $amount = (float) ($tx['transactionAmount']['amount'] ?? 0);
            if ($amount < 0 && isset($tx['debtorName'])) {
                $debitDebtorNames[$tx['debtorName']] = ($debitDebtorNames[$tx['debtorName']] ?? 0) + 1;
            }
        }
        arsort($debitDebtorNames);
        $ownName = array_key_first($debitDebtorNames);

        if ($ownName) {
            $this->newLine();
            $this->warn("Likely OWN company name (most frequent debtorName for debits): \"{$ownName}\"");

            // Count how many creditorNames match own company
            $ownAsCreditor = 0;
            $ownAsCreditorDebits = 0;
            foreach ($transactions as $tx) {
                $cn = $tx['creditorName'] ?? null;
                if ($cn === $ownName) {
                    $ownAsCreditor++;
                    if ((float) ($tx['transactionAmount']['amount'] ?? 0) < 0) {
                        $ownAsCreditorDebits++;
                    }
                }
            }
            $this->line("  → creditorName = own company: {$ownAsCreditor} total ({$ownAsCreditorDebits} are debits = WRONG counterparty!)");
        }
    }

    protected function analyzeIbans(array $transactions): void
    {
        $this->info('=== IBAN ANALYSIS ===');

        $debtorIbans = [];
        $creditorIbans = [];
        $debitWithCreditorIban = 0;
        $creditWithDebtorIban = 0;

        foreach ($transactions as $tx) {
            $amount = (float) ($tx['transactionAmount']['amount'] ?? 0);
            $isDebit = $amount < 0;

            $dIban = $tx['debtorAccount']['iban'] ?? null;
            $cIban = $tx['creditorAccount']['iban'] ?? null;

            if ($dIban) {
                $debtorIbans[$dIban] = ($debtorIbans[$dIban] ?? 0) + 1;
            }
            if ($cIban) {
                $creditorIbans[$cIban] = ($creditorIbans[$cIban] ?? 0) + 1;
            }

            if ($isDebit && $cIban) {
                $debitWithCreditorIban++;
            }
            if (!$isDebit && $dIban) {
                $creditWithDebtorIban++;
            }
        }

        arsort($debtorIbans);
        arsort($creditorIbans);

        $debits = array_filter($transactions, fn($tx) => (float) ($tx['transactionAmount']['amount'] ?? 0) < 0);
        $credits = array_filter($transactions, fn($tx) => (float) ($tx['transactionAmount']['amount'] ?? 0) >= 0);

        $this->line("Debits with creditorAccount.iban (counterparty IBAN for debits): {$debitWithCreditorIban}/" . count($debits));
        $this->line("Credits with debtorAccount.iban (counterparty IBAN for credits): {$creditWithDebtorIban}/" . count($credits));
        $this->newLine();

        $this->line('Unique debtorAccount.iban values:');
        $rows = [];
        foreach ($debtorIbans as $iban => $count) {
            $rows[] = [$iban, $count];
        }
        $this->table(['debtorAccount.iban', 'Count'], $rows);

        if (!empty($creditorIbans)) {
            $this->line('Unique creditorAccount.iban values:');
            $rows = [];
            foreach ($creditorIbans as $iban => $count) {
                $rows[] = [$iban, $count];
            }
            $this->table(['creditorAccount.iban', 'Count'], $rows);
        } else {
            $this->warn('creditorAccount.iban: NEVER provided by the bank!');
        }

        // Detect own IBAN
        $ownIban = array_key_first($debtorIbans);
        if ($ownIban) {
            $this->warn("Likely OWN IBAN (most frequent debtorAccount.iban): {$ownIban}");
        }
    }

    protected function analyzeAdditionalInformation(array $transactions): void
    {
        $this->info('=== ADDITIONAL INFORMATION ANALYSIS ===');

        $withAdditional = array_filter($transactions, fn($tx) => !empty($tx['additionalInformation']));
        $this->line("Transactions with additionalInformation: " . count($withAdditional) . "/" . count($transactions));

        if (empty($withAdditional)) {
            return;
        }

        // Try to parse IBANs from additionalInformation
        $containsIban = 0;
        $containsBic = 0;
        $ibanIsOwn = 0;

        $ownIban = null;
        $debtorIbans = [];
        foreach ($transactions as $tx) {
            $iban = $tx['debtorAccount']['iban'] ?? null;
            if ($iban) {
                $debtorIbans[$iban] = ($debtorIbans[$iban] ?? 0) + 1;
            }
        }
        arsort($debtorIbans);
        $ownIban = array_key_first($debtorIbans);

        foreach ($withAdditional as $tx) {
            $info = $tx['additionalInformation'];
            if (preg_match('/[A-Z]{2}\d{2}[A-Z0-9]{10,30}/', $info, $m)) {
                $containsIban++;
                if ($m[0] === $ownIban) {
                    $ibanIsOwn++;
                }
            }
            if (preg_match('/[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?/', $info)) {
                $containsBic++;
            }
        }

        $this->line("  Contains IBAN: {$containsIban}");
        $this->line("  Contains BIC: {$containsBic}");
        if ($ownIban) {
            $this->line("  IBAN is OWN ({$ownIban}): {$ibanIsOwn}");
            $counterpartyIbans = $containsIban - $ibanIsOwn;
            $this->line("  IBAN is COUNTERPARTY: {$counterpartyIbans}");
        }

        // Show samples by transaction type
        $this->newLine();
        $this->line('Sample additionalInformation (first 5):');
        $i = 0;
        foreach ($withAdditional as $tx) {
            if ($i >= 5) break;
            $txId = $tx['transactionId'] ?? 'no-id';
            $amount = $tx['transactionAmount']['amount'] ?? '?';
            $this->line("  [{$txId}] {$amount} EUR:");
            $this->line("    " . str_replace("\n", "\n    ", $tx['additionalInformation']));
            $i++;
        }
    }

    protected function analyzeRemittanceInformation(array $transactions): void
    {
        $this->info('=== REMITTANCE INFORMATION ANALYSIS ===');

        $unstructured = 0;
        $unstructuredArray = 0;
        $structured = 0;
        $structuredArray = 0;

        foreach ($transactions as $tx) {
            if (!empty($tx['remittanceInformationUnstructured'])) $unstructured++;
            if (!empty($tx['remittanceInformationUnstructuredArray'])) $unstructuredArray++;
            if (!empty($tx['remittanceInformationStructured'])) $structured++;
            if (!empty($tx['remittanceInformationStructuredArray'])) $structuredArray++;
        }

        $total = count($transactions);
        $this->table(['Field', 'Present', '%'], [
            ['remittanceInformationUnstructured', "{$unstructured}/{$total}", round($unstructured / $total * 100) . '%'],
            ['remittanceInformationUnstructuredArray', "{$unstructuredArray}/{$total}", round($unstructuredArray / $total * 100) . '%'],
            ['remittanceInformationStructured', "{$structured}/{$total}", round($structured / $total * 100) . '%'],
            ['remittanceInformationStructuredArray', "{$structuredArray}/{$total}", round($structuredArray / $total * 100) . '%'],
        ]);

        // Show samples
        $withRemittance = array_filter($transactions, fn($tx) => !empty($tx['remittanceInformationUnstructured']));
        if (!empty($withRemittance)) {
            $this->line('Sample remittanceInformationUnstructured (first 5):');
            $i = 0;
            foreach ($withRemittance as $tx) {
                if ($i >= 5) break;
                $txId = $tx['transactionId'] ?? 'no-id';
                $this->line("  [{$txId}]: {$tx['remittanceInformationUnstructured']}");
                $i++;
            }
        }
    }

    protected function fieldPresent(array $tx, string $field): bool
    {
        if (isset($tx[$field])) {
            if (is_string($tx[$field])) return $tx[$field] !== '';
            if (is_array($tx[$field])) return !empty($tx[$field]);
            return true;
        }
        return false;
    }
}
