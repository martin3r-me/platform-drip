<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\InternalTransfer;

class TransactionService
{
    /**
     * Normalisiert alle Transaktionen eines Teams. Optional: nur seit Datum.
     */
    public function normalizeTeam(int $teamId, ?Carbon $since = null): int
    {
        $accountIds = BankAccount::query()
            ->where('team_id', $teamId)
            ->pluck('id')
            ->all();

        if (empty($accountIds)) {
            return 0;
        }

        return $this->normalizeAccounts($teamId, $accountIds, $since);
    }

    /**
     * Normalisiert Transaktionen für eine oder mehrere Konten-IDs (teamgescoped).
     */
    public function normalizeAccounts(int $teamId, array $accountIds, ?Carbon $since = null): int
    {
        $since = $since ?: null; // explizit null zulassen

        // Account-Metadaten lookup (für Gruppen/IBAN-Matching)
        $accountsById = BankAccount::query()
            ->where('team_id', $teamId)
            ->whereIn('id', $accountIds)
            ->get(['id', 'team_id', 'group_id', 'iban'])
            ->keyBy('id');

        if ($accountsById->isEmpty()) {
            return 0;
        }

        $ibanToAccountId = $accountsById
            ->filter(fn ($a) => !empty($a->iban))
            ->mapWithKeys(fn ($a) => [$a->iban => $a->id])
            ->all();

        $updated = 0;

        // In Chunks verarbeiten
        $query = BankTransaction::query()
            ->where('team_id', $teamId)
            ->whereIn('bank_account_id', $accountIds)
            ->orderBy('id');

        if ($since) {
            // bevorzugt booked_at, sonst created_at
            $query->where(function ($q) use ($since) {
                $q->whereNotNull('booked_at')->where('booked_at', '>=', $since)
                  ->orWhere(function ($q2) use ($since) {
                      $q2->whereNull('booked_at')->where('created_at', '>=', $since);
                  });
            });
        }

        $query->chunkById(1000, function (Collection $txChunk) use (&$updated, $accountsById, $ibanToAccountId) {
            DB::transaction(function () use ($txChunk, &$updated, $accountsById, $ibanToAccountId) {
                foreach ($txChunk as $tx) {
                    $changed = false;

                    // 1) Gruppe vom Konto auf die Transaktion spiegeln (falls Feld existiert)
                    $account = $accountsById->get($tx->bank_account_id);
                    if ($account) {
                        if ($tx->getAttribute('group_id') !== $account->group_id) {
                            $tx->setAttribute('group_id', $account->group_id); // kann null sein
                            $changed = true;
                        }
                    }

                    // 2) Direction anhand Amount sicherstellen
                    if ($tx->amount !== null) {
                        $expectedDirection = ((float) $tx->amount) >= 0 ? 'credit' : 'debit';
                        if ($tx->direction !== $expectedDirection) {
                            $tx->direction = $expectedDirection;
                            $changed = true;
                        }
                        // Betrag auf 2 Nachkommastellen normalisieren
                        $normalizedAmount = round((float) $tx->amount, 2);
                        if ((float) $tx->amount !== $normalizedAmount) {
                            $tx->amount = $normalizedAmount;
                            $changed = true;
                        }
                    }

                    // 3) booked_at-Fallback setzen, wenn leer
                    if (empty($tx->booked_at)) {
                        // bevorzugt booking_date/value_date falls vorhanden, sonst created_at
                        $fallbackDate = $tx->booking_date ?? $tx->value_date ?? $tx->created_at;
                        if ($fallbackDate) {
                            $tx->booked_at = is_string($fallbackDate) ? Carbon::parse($fallbackDate) : $fallbackDate;
                            $changed = true;
                        }
                    }

                    // 3b) Currency vereinheitlichen
                    if (!empty($tx->currency)) {
                        $upper = strtoupper(trim($tx->currency));
                        if ($tx->currency !== $upper) {
                            $tx->currency = $upper;
                            $changed = true;
                        }
                    }

                    // 4) Referenz/Remittance vereinheitlichen
                    if (empty($tx->reference)) {
                        $ref = $tx->remittance_information
                            ?? ($tx->entry_reference ?? null)
                            ?? ($tx->end_to_end_id ?? null);
                        if ($ref) {
                            $tx->reference = trim((string) $ref);
                            $changed = true;
                        }
                    }

                    // 5) Gegenpartei-Name/IBAN konsistent setzen
                    $counterpartyName = $tx->creditor_name ?? $tx->debtor_name ?? null;
                    if (property_exists($tx, 'counterparty_name') && empty($tx->counterparty_name) && $counterpartyName) {
                        $tx->counterparty_name = trim($counterpartyName);
                        $changed = true;
                    }

                    // 4) Interne Umbuchungen erkennen (IBAN zu Teamkonto)
                    //    Heuristik: Wenn debtor/creditor IBAN eine Team-IBAN ist → Flag und Gegenbuchungspartner merken
                    $counterpartyIban = $tx->creditor_account_iban ?? $tx->debtor_account_iban ?? null;
                    if ($counterpartyIban && isset($ibanToAccountId[$counterpartyIban])) {
                        if (!$tx->getAttribute('is_internal_transfer')) {
                            $tx->setAttribute('is_internal_transfer', true);
                            $changed = true;
                        }

                        // Optional: Gegenbuchung referenzieren (gleicher Betrag gegensätzlich, nahe am Datum)
                        // Leichtgewichtige Heuristik, nur wenn Feld existiert
                        if (empty($tx->getAttribute('internal_transaction_id'))) {
                            $match = BankTransaction::query()
                                ->where('team_id', $tx->team_id)
                                ->where('bank_account_id', $ibanToAccountId[$counterpartyIban])
                                ->where('currency', $tx->currency)
                                ->whereBetween('booked_at', [
                                    ($tx->booked_at ? Carbon::parse($tx->booked_at)->copy()->subDays(2) : Carbon::now()->subDays(2)),
                                    ($tx->booked_at ? Carbon::parse($tx->booked_at)->copy()->addDays(2) : Carbon::now()->addDays(2)),
                                ])
                                ->where(function ($q) use ($tx) {
                                    // gegensätzlicher Betrag innerhalb einer Toleranz von 1 Cent
                                    $q->where('amount', '=', -1 * (float) $tx->amount)
                                      ->orWhereRaw('ABS(amount + ?) < 0.01', [(float) $tx->amount]);
                                })
                                ->orderByDesc('id')
                                ->first();

                            if ($match) {
                                $tx->setAttribute('internal_transaction_id', $match->id);
                                $changed = true;

                                // Materialisierte Übersicht schreiben (idempotent)
                                $fromAccountId = null;
                                $toAccountId = null;
                                $amountAbs = abs((float) $tx->amount);

                                if (($tx->direction ?? null) === 'debit') {
                                    $fromAccountId = (int) $tx->bank_account_id;
                                    $toAccountId = (int) $match->bank_account_id;
                                } else {
                                    $fromAccountId = (int) $match->bank_account_id;
                                    $toAccountId = (int) $tx->bank_account_id;
                                }

                                $transferredAt = $tx->booked_at ? Carbon::parse($tx->booked_at)->toDateString() : Carbon::now()->toDateString();

                                InternalTransfer::firstOrCreate(
                                    [
                                        'team_id' => (int) $tx->team_id,
                                        'source_transaction_id' => (int) $tx->id,
                                        'target_transaction_id' => (int) $match->id,
                                    ],
                                    [
                                        'from_account_id' => $fromAccountId,
                                        'to_account_id' => $toAccountId,
                                        'transferred_at' => $transferredAt,
                                        'amount' => $amountAbs,
                                        'currency' => $tx->currency ?? 'EUR',
                                        'reference' => $tx->reference ?? $tx->remittance_information ?? null,
                                    ]
                                );
                            }
                        }
                    }

                    // 6) Grobtyp aus Codes/Heuristik ableiten (income/expense/transfer)
                    $type = $tx->getAttribute('transaction_type_simple');
                    if (!$type) {
                        $type = $this->inferSimpleType(
                            direction: $tx->direction ?? null,
                            isInternal: (bool) $tx->getAttribute('is_internal_transfer'),
                            bankCode: $tx->bank_transaction_code ?? null,
                        );
                        if ($type) {
                            $tx->setAttribute('transaction_type_simple', $type);
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $tx->save();
                        $updated++;
                    }
                }
            });
        });

        Log::info('TransactionService: normalization done', [
            'teamId' => $teamId,
            'accounts' => $accountIds,
            'since' => $since?->toDateTimeString(),
            'updated' => $updated,
        ]);

        return $updated;
    }

    private function inferSimpleType(?string $direction, bool $isInternal, ?string $bankCode): ?string
    {
        if ($isInternal) {
            return 'transfer';
        }

        $code = strtoupper((string) $bankCode);
        if ($code) {
            if (str_contains($code, 'PMNT')) {
                return $direction === 'credit' ? 'income' : 'expense';
            }
            if (str_contains($code, 'CARD')) {
                return 'expense';
            }
            if (str_contains($code, 'XFER')) {
                return 'transfer';
            }
            if (str_contains($code, 'CHRG')) {
                return 'expense';
            }
            if (str_contains($code, 'REFD') || str_contains($code, 'RFD')) {
                return 'income';
            }
        }

        if ($direction === 'credit') return 'income';
        if ($direction === 'debit') return 'expense';
        return null;
    }
}


