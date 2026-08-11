<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;
use Platform\Core\Traits\Encryptable;

class BankTransaction extends Model
{
    use SoftDeletes, Encryptable;

    /** Beleg-Status eines Eingangs (siehe Migration add_invoice_status_…). */
    public const INVOICE_STATUS_OPEN = 'open';
    public const INVOICE_STATUS_MATCHED = 'matched';
    public const INVOICE_STATUS_PARTIAL = 'partial';
    public const INVOICE_STATUS_NO_INVOICE = 'no_invoice';

    /** Zugeordnete Cent im laufenden Match-Durchgang (siehe allocatedCents()). */
    private int $allocationCache = 0;

    private bool $allocationCacheWarm = false;

    protected $table = 'drip_bank_transactions';

    protected $fillable = [
        'uuid', 'team_id', 'user_id', 'bank_account_id', 'category_id', 'recurring_pattern_id',
        'transaction_id', 'booking_date', 'booking_date_time', 'value_date', 'value_date_time', 'booked_at',
        'amount', 'currency', 'direction', 'status', 'is_disregarded', 'category_skipped', 'invoice_status', 'metadata',
        'remittance_information', 'remittance_information_structured', 'remittance_information_structured_array',
        'remittance_information_unstructured', 'remittance_information_unstructured_array',
        'debtor_name', 'creditor_name', 'debtor_account_iban', 'creditor_account_iban',
        'debtor_agent', 'creditor_agent', 'transaction_type', 'bank_transaction_code',
        'proprietary_bank_transaction_code', 'internal_transaction_id', 'entry_reference',
        'end_to_end_id', 'mandate_id', 'merchant_category_code', 'check_id', 'creditor_id',
        'purpose_code', 'ultimate_creditor', 'ultimate_debtor', 'currency_exchange',
        'balance_after_transaction', 'additional_data_structured', 'additional_information',
        'additional_information_structured',
        // Legacy fields
        'counterparty_name', 'counterparty_iban', 'reference',
    ];

    protected $casts = [
        'is_disregarded' => 'boolean',
        'category_skipped' => 'boolean',
        'booked_at' => 'date',
        'booking_date' => 'date',
        'booking_date_time' => 'datetime',
        'value_date' => 'date',
        'value_date_time' => 'datetime',
        'metadata' => 'array',
        'currency_exchange' => 'array',
        'additional_data_structured' => 'array',
        'remittance_information_structured_array' => 'array',
        'remittance_information_unstructured_array' => 'array',
    ];

    protected array $encryptable = [
        // Beträge und Salden
        'amount' => 'decimal:4',
        'balance_after_transaction' => 'json',

        // IBANs
        'counterparty_iban' => 'string',
        'debtor_account_iban' => 'string',
        'creditor_account_iban' => 'string',

        // Namen (personenbezogen)
        'counterparty_name' => 'string',
        'debtor_name' => 'string',
        'creditor_name' => 'string',
        'ultimate_creditor' => 'string',
        'ultimate_debtor' => 'string',

        // Bank-Routing (BICs)
        'debtor_agent' => 'string',
        'creditor_agent' => 'string',

        // Verwendungszweck (kann persönlich sein)
        'reference' => 'string',
        'remittance_information' => 'string',
        'remittance_information_structured' => 'string',
        'remittance_information_unstructured' => 'string',

        // Zusätzliche Informationen
        'additional_information' => 'string',
        'additional_information_structured' => 'string',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            do {
                $uuid = UuidV7::generate();
            } while (self::where('uuid', $uuid)->exists());

            $model->uuid = $model->uuid ?: $uuid;
            if (! $model->user_id && $model->bankAccount) {
                $model->user_id = $model->bankAccount->user_id;
            }
            if (! $model->team_id && $model->bankAccount) {
                $model->team_id = $model->bankAccount->team_id;
            }
        });

        // Live-Automatch: ein neuer Eingang wird sofort gegen die offenen
        // Ausgangsrechnungen geprüft (deckt alle Import-Wege ab: MOSS,
        // GoCardless, …). Rein lokal (kein easybill-Call), defensiv — ein
        // Fehler darf den Import nie brechen. Setzt nebenbei den invoice_status,
        // also auch „no_invoice" für belegfreie Eingänge (Finanzamt, Zuschüsse).
        static::created(function (self $model) {
            if ($model->direction !== 'credit' || $model->is_disregarded) {
                return;
            }

            try {
                app(\Platform\Drip\Services\InvoiceMatchService::class)->matchTransaction($model);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Drip: Live-Rechnungsabgleich fehlgeschlagen', [
                    'transaction_id' => $model->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BankTransactionCategory::class, 'category_id');
    }

    public function recurringPatterns(): BelongsToMany
    {
        return $this->belongsToMany(RecurringPattern::class, 'drip_bank_transaction_recurring_pattern', 'bank_transaction_id', 'recurring_pattern_id');
    }

    public function sourceTransfer(): HasOne
    {
        return $this->hasOne(InternalTransfer::class, 'source_transaction_id');
    }

    public function targetTransfer(): HasOne
    {
        return $this->hasOne(InternalTransfer::class, 'target_transaction_id');
    }

    // Scopes
    public function scopeForTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeVisibleFor($query, \Platform\Core\Models\User $user)
    {
        $teamId = $user->current_team_id;
        return $query->where('team_id', $teamId);
    }

    /** Nur Transaktionen, die im Cashflow zählen (ohne abgelehnte/gelöschte MOSS-Zahlungen). */
    public function scopeCounted($query)
    {
        return $query->where('is_disregarded', false);
    }

    public function scopeDisregarded($query)
    {
        return $query->where('is_disregarded', true);
    }

    /** Posteingang: gezählte TX ohne Kategorie, die noch nicht bewusst geparkt wurden. */
    public function scopeNeedsCategory($query)
    {
        return $query->where('is_disregarded', false)
            ->where('category_skipped', false)
            ->whereNull('category_id');
    }

    /** Bewusst ohne Kategorie geparkt (gesichtet, aber keine Kategorie gewollt). */
    public function scopeCategorySkipped($query)
    {
        return $query->where('is_disregarded', false)->where('category_skipped', true);
    }

    // ── Beleg-Zuordnung ──

    /**
     * Belege, die dieser Eingang begleicht. n:m — eine Überweisung deckt oft
     * mehrere Rechnungen ab (Sammelzahlung).
     */
    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(
            DripInvoice::class,
            'drip_invoice_transaction',
            'bank_transaction_id',
            'drip_invoice_id'
        )->withPivot(['team_id', 'amount_applied_cents', 'match_type', 'confidence', 'is_confirmed', 'confirmed_at'])
         ->withTimestamps();
    }

    /** Bereits auf Belege verteilter Anteil dieses Eingangs, in Cent. */
    public function allocatedCents(): int
    {
        if ($this->allocationCacheWarm === false) {
            $this->allocationCacheWarm = true;
            $rows = $this->relationLoaded('invoices') ? $this->invoices : $this->invoices()->get();
            $this->allocationCache = (int) $rows->sum(fn ($i) => (int) $i->pivot->amount_applied_cents);
        }

        return $this->allocationCache;
    }

    /** Im laufenden Match-Durchgang zugeordneten Betrag mitschreiben. */
    public function addAllocationCache(int $cents): void
    {
        $this->allocationCache = $this->allocatedCents() + $cents;
    }

    /** Noch nicht durch Belege gedeckter Anteil, in Cent. */
    public function unallocatedCents(): int
    {
        return max(0, (int) round(abs((float) $this->amount) * 100) - $this->allocatedCents());
    }

    /** Eingänge, die noch auf einen Beleg warten — die eigentliche Worklist. */
    public function scopeAwaitingInvoice($query)
    {
        return $query->where('direction', 'credit')
            ->where('is_disregarded', false)
            ->whereIn('invoice_status', [self::INVOICE_STATUS_OPEN, self::INVOICE_STATUS_PARTIAL]);
    }

    /** Bewusst belegfrei (Finanzamt, Zuschüsse, Ausleihungen, Kontoabschlüsse). */
    public function scopeWithoutInvoiceExpected($query)
    {
        return $query->where('invoice_status', self::INVOICE_STATUS_NO_INVOICE);
    }
}


