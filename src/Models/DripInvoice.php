<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function scopeDirection(Builder $query, string $direction): Builder
    {
        return $query->where('direction', $direction);
    }

    public function scopeMatched(Builder $query): Builder
    {
        return $query->where('match_status', 'matched');
    }
}
