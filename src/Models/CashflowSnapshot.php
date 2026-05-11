<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashflowSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'drip_cashflow_snapshots';

    const SENTINEL_ALL = 0;
    const SENTINEL_HASH_ALL = '';

    protected $fillable = [
        'team_id',
        'bank_account_id',
        'category_id',
        'counterparty_hash',
        'direction',
        'period_type',
        'period_key',
        'period_start',
        'period_end',
        'total_amount',
        'transaction_count',
        'computed_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_amount' => 'decimal:2',
        'computed_at' => 'datetime',
    ];

    // ── Relations (convenience, not FK-backed) ──

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BankTransactionCategory::class, 'category_id');
    }

    // ── Scopes ──

    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('period_type', 'month');
    }

    public function scopeWeekly(Builder $query): Builder
    {
        return $query->where('period_type', 'week');
    }

    public function scopeForCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('category_id', $categoryId)
            ->where('counterparty_hash', self::SENTINEL_HASH_ALL);
    }

    public function scopeForCounterparty(Builder $query, string $hash): Builder
    {
        return $query->where('counterparty_hash', $hash)
            ->where('category_id', self::SENTINEL_ALL);
    }

    public function scopeForBankAccount(Builder $query, ?int $bankAccountId): Builder
    {
        return $query->where('bank_account_id', $bankAccountId ?? self::SENTINEL_ALL);
    }

    public function scopeTeamWide(Builder $query): Builder
    {
        return $query->where('bank_account_id', self::SENTINEL_ALL);
    }
}
