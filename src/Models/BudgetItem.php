<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;

class BudgetItem extends Model
{
    use SoftDeletes;

    protected $table = 'drip_budget_items';

    protected $fillable = [
        'uuid', 'team_id', 'user_id', 'category_id',
        'name', 'direction', 'amount', 'frequency',
        'day_of_month', 'start_date', 'end_date', 'planned_date',
        'is_active', 'notes',
        'status', 'source_type', 'source_counterparty', 'source_iban',
        'source_month_count', 'source_avg_amount',
        'suggested_at', 'confirmed_at', 'dismissed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'source_avg_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'planned_date' => 'date',
        'is_active' => 'boolean',
        'day_of_month' => 'integer',
        'source_month_count' => 'integer',
        'suggested_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            do {
                $uuid = UuidV7::generate();
            } while (self::where('uuid', $uuid)->exists());

            $model->uuid = $model->uuid ?: $uuid;
            $model->user_id ??= Auth::id();
            $model->team_id ??= Auth::user()?->current_team_id;
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BankTransactionCategory::class, 'category_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(BudgetItemPeriod::class, 'budget_item_id');
    }

    public function generatePeriods(int $monthsAhead = 12): int
    {
        return app(\Platform\Drip\Services\BudgetPeriodService::class)
            ->generatePeriodsForItem($this, $monthsAhead);
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

    public function scopeSuggested(Builder $query): Builder
    {
        return $query->where('status', 'suggested')->whereNull('dismissed_at');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['archived']);
    }

    // ── Lifecycle Methods ──

    public function confirm(): self
    {
        $this->update([
            'status' => 'active',
            'is_active' => true,
            'confirmed_at' => now(),
        ]);
        return $this;
    }

    public function dismiss(): self
    {
        $this->update([
            'dismissed_at' => now(),
        ]);
        return $this;
    }

    public function pause(): self
    {
        $this->update([
            'status' => 'paused',
            'is_active' => false,
        ]);
        return $this;
    }

    public function resume(): self
    {
        $this->update([
            'status' => 'active',
            'is_active' => true,
        ]);
        return $this;
    }

    public function archive(): self
    {
        $this->update([
            'status' => 'archived',
            'is_active' => false,
        ]);
        return $this;
    }

    // ── Fulfillment ──

    /**
     * Calculate fulfillment for a given month.
     * Centralizes the duplicated query logic from Budgets.php, Dashboard.php, and MCP Tool.
     */
    public function fulfillmentForMonth(Carbon $monthStart, int $teamId): array
    {
        $monthlyBudget = $this->monthlyAmount();
        $actual = 0;

        if ($this->category_id) {
            $monthEnd = $monthStart->copy()->endOfMonth();

            $actual = BankTransaction::where('team_id', $teamId)
                ->where('category_id', $this->category_id)
                ->where('direction', $this->direction)
                ->where(function ($q) use ($monthStart, $monthEnd) {
                    $q->where(function ($inner) use ($monthStart, $monthEnd) {
                        $inner->whereNotNull('booked_at')
                            ->whereBetween('booked_at', [$monthStart, $monthEnd]);
                    })->orWhere(function ($or) use ($monthStart, $monthEnd) {
                        $or->whereNull('booked_at')
                            ->whereBetween('created_at', [$monthStart, $monthEnd]);
                    });
                })
                ->get(['amount'])
                ->sum(fn ($t) => abs((float) $t->amount));
        }

        $percent = $monthlyBudget > 0 ? round($actual / $monthlyBudget * 100, 1) : 0;

        return [
            'budget' => $monthlyBudget,
            'actual' => $actual,
            'percent' => $percent,
        ];
    }

    /**
     * Normalize budget amount to monthly basis.
     */
    public function monthlyAmount(): float
    {
        $amount = (float) $this->amount;

        return match ($this->frequency) {
            'once' => $amount,
            'weekly' => $amount * 4.33,
            'monthly' => $amount,
            'quarterly' => $amount / 3,
            'yearly' => $amount / 12,
            default => $amount,
        };
    }
}
