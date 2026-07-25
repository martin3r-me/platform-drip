<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashflowSignal extends Model
{
    use SoftDeletes;

    protected $table = 'drip_cashflow_signals';

    protected $fillable = [
        'team_id', 'provider_key', 'external_id',
        'label', 'direction', 'amount', 'override_amount',
        'expected_date', 'override_date',
        'confidence', 'confidence_level',
        'counterparty', 'category', 'url',
        'status', 'meta',
        'resolved_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'override_amount' => 'decimal:2',
        'confidence' => 'decimal:2',
        'expected_date' => 'date',
        'override_date' => 'date',
        'resolved_at' => 'datetime',
        'meta' => 'array',
    ];

    // ── Computed ──

    /**
     * Returns override_amount if set, otherwise amount.
     */
    public function effectiveAmount(): float
    {
        return (float) ($this->override_amount ?? $this->amount);
    }

    /**
     * Returns effective_date (override or original).
     */
    public function effectiveDate(): \Illuminate\Support\Carbon
    {
        return $this->override_date ?? $this->expected_date;
    }

    /**
     * Returns amount weighted by confidence.
     */
    public function weightedAmount(): float
    {
        return $this->effectiveAmount() * (float) $this->confidence;
    }

    // ── Scopes ──

    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Signals relevant for forecast: active only (not dismissed, pinned, or resolved).
     */
    public function scopeForForecast(Builder $query): Builder
    {
        $today = now()->startOfDay();

        return $query->where('status', 'active')
            ->where(function ($q) use ($today) {
                $q->where(function ($inner) use ($today) {
                    $inner->whereNotNull('override_date')
                        ->where('override_date', '>=', $today);
                })->orWhere(function ($or) use ($today) {
                    $or->whereNull('override_date')
                        ->where('expected_date', '>=', $today);
                });
            });
    }

    public function scopeNotDismissed(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['dismissed']);
    }

    // ── Actions ──

    public function dismiss(): self
    {
        $this->update(['status' => 'dismissed']);
        return $this;
    }

    public function resolve(): self
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
        return $this;
    }
}
