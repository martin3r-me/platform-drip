<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Gespiegelte Ausgangsrechnung (easybill/lexoffice) — Basis für die Belege-View
 * und den Abgleich gegen die tatsächlichen Bank-Eingänge. Beträge liegen als
 * Integer-Cent vor; die Euro-Accessoren rechnen für die Anzeige um.
 */
class DripInvoice extends Model
{
    use SoftDeletes;

    protected $table = 'drip_invoices';

    protected $fillable = [
        'uuid', 'team_id', 'provider', 'external_id', 'number', 'type', 'direction',
        'external_status', 'is_draft',
        'customer_external_id', 'customer_name', 'customer_iban',
        'amount_gross_cents', 'amount_net_cents', 'paid_amount_cents', 'currency',
        'document_date', 'due_date', 'external_paid_at',
        'match_status', 'matched_transaction_id', 'match_confidence', 'matched_at',
        'metadata',
    ];

    protected $casts = [
        'is_draft' => 'boolean',
        'amount_gross_cents' => 'integer',
        'amount_net_cents' => 'integer',
        'paid_amount_cents' => 'integer',
        'document_date' => 'date',
        'due_date' => 'date',
        'external_paid_at' => 'datetime',
        'matched_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Merker für den laufenden Match-Durchgang: im selben Lauf zugeordnete
     * Beträge, damit Folgeschritte nicht gegen die noch ungeladene Pivot rechnen.
     *
     * @var array<int,int> transaction_id => cents
     */
    private array $allocationCache = [];

    /** @var array<int,string> transaction_id => match_type */
    private array $matchTypeCache = [];

    private bool $allocationCacheWarm = false;

    /** Wurde dieser Beleg im laufenden Durchgang neu zugeordnet? */
    public bool $wasRecentlyAllocated = false;

    protected static function booted(): void
    {
        static::creating(function (self $invoice) {
            if (empty($invoice->uuid)) {
                $invoice->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Relations ──

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'matched_transaction_id');
    }

    /**
     * Alle Bank-Transaktionen, die auf diesen Beleg einzahlen. n:m, weil eine
     * Sammelzahlung mehrere Belege deckt und ein Beleg in Raten bezahlt werden kann.
     */
    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(
            BankTransaction::class,
            'drip_invoice_transaction',
            'drip_invoice_id',
            'bank_transaction_id'
        )->withPivot(['team_id', 'amount_applied_cents', 'match_type', 'confidence', 'is_confirmed', 'confirmed_at'])
         ->withTimestamps();
    }

    // ── Zuordnung / Bezahlstatus ──

    /** Bereits durch Bank-Eingänge gedeckter Betrag in Cent. */
    public function allocatedCents(): int
    {
        $this->warmAllocationCache();

        return array_sum($this->allocationCache);
    }

    /** Noch offener Betrag in Cent (nie negativ). */
    public function openCents(): int
    {
        return max(0, abs((int) $this->amount_gross_cents) - $this->allocatedCents());
    }

    public function getOpenAmountAttribute(): float
    {
        return round($this->openCents() / 100, 2);
    }

    /** Führende Transaktion — die mit dem größten Anteil. */
    public function primaryTransactionId(): ?int
    {
        $this->warmAllocationCache();

        if ($this->allocationCache === []) {
            return null;
        }

        $cache = $this->allocationCache;
        arsort($cache);

        return (int) array_key_first($cache);
    }

    /** Beste Confidence über alle Zuordnungen — für die Anzeige. */
    public function bestConfidence(): ?string
    {
        $this->warmAllocationCache();

        foreach (['number', 'amount_party', 'amount', 'manual'] as $rank) {
            if (in_array($rank, $this->matchTypeCache, true)) {
                return $rank;
            }
        }

        return $this->matchTypeCache === [] ? null : (string) reset($this->matchTypeCache);
    }

    /** Im laufenden Durchgang zugeordneten Betrag mitschreiben. */
    public function addAllocationCache(int $transactionId, int $cents, string $matchType = 'number'): void
    {
        $this->warmAllocationCache();
        $this->allocationCache[$transactionId] = ($this->allocationCache[$transactionId] ?? 0) + $cents;
        $this->matchTypeCache[$transactionId] = $matchType;
    }

    private function warmAllocationCache(): void
    {
        if ($this->allocationCacheWarm) {
            return;
        }

        $this->allocationCacheWarm = true;

        if (!$this->exists) {
            return;
        }

        $rows = $this->relationLoaded('transactions')
            ? $this->transactions
            : $this->transactions()->get();

        foreach ($rows as $tx) {
            $this->allocationCache[(int) $tx->id] = (int) $tx->pivot->amount_applied_cents;
            $this->matchTypeCache[(int) $tx->id] = (string) $tx->pivot->match_type;
        }
    }

    // ── Accessors ──

    public function getAmountGrossAttribute(): float
    {
        return round(($this->amount_gross_cents ?? 0) / 100, 2);
    }

    public function getAmountNetAttribute(): ?float
    {
        return $this->amount_net_cents === null ? null : round($this->amount_net_cents / 100, 2);
    }

    /** Kalendermonat des Belegdatums als Schlüssel (YYYY-MM) für die Gruppierung. */
    public function getMonthKeyAttribute(): ?string
    {
        return $this->document_date?->format('Y-m');
    }

    public function isMatched(): bool
    {
        return $this->match_status === 'matched';
    }

    // ── Scopes ──

    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    /** Nur echte Ausgangsrechnungen (keine Entwürfe, keine Stornos/Gutschriften). */
    public function scopeInvoices(Builder $query): Builder
    {
        return $query->where('type', 'INVOICE')->where('is_draft', false);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('match_status', 'open');
    }

    /** Noch nicht (vollständig) bezahlt — offen ODER teilbezahlt. */
    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereIn('match_status', ['open', 'partial']);
    }

    /** Unbezahlt und Fälligkeitsdatum überschritten. */
    public function scopeOverdue(Builder $query, ?\DateTimeInterface $asOf = null): Builder
    {
        return $query->unpaid()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $asOf ?? now());
    }

    public function scopeDirection(Builder $query, string $direction): Builder
    {
        return $query->where('direction', $direction);
    }

    public function scopeMatched(Builder $query): Builder
    {
        return $query->where('match_status', 'matched');
    }
}
