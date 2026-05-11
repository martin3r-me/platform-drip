<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\Uid\UuidV7;

class BudgetItemPeriod extends Model
{
    use SoftDeletes;

    protected $table = 'drip_budget_item_periods';

    protected $fillable = [
        'uuid', 'team_id', 'budget_item_id',
        'period_start', 'period_end', 'expected_date',
        'planned_amount', 'actual_amount', 'percent',
        'status', 'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'expected_date' => 'date',
        'planned_amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'percent' => 'decimal:1',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            do {
                $uuid = UuidV7::generate();
            } while (self::where('uuid', $uuid)->exists());

            $model->uuid = $model->uuid ?: $uuid;
        });
    }

    public function budgetItem(): BelongsTo
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }

    // ── Scopes ──

    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeForDateRange(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where('period_start', '>=', $from)
            ->where('period_end', '<=', $to);
    }

    // ── Methods ──

    public function skip(): self
    {
        $this->update(['status' => 'skipped']);
        return $this;
    }

    public function updateFulfillment(int $teamId): self
    {
        $item = $this->budgetItem;
        $actual = 0;

        if ($item->category_id) {
            $query = BankTransaction::where('team_id', $teamId)
                ->where('category_id', $item->category_id)
                ->where('direction', $item->direction);

            if ($item->bank_account_id) {
                $query->where('bank_account_id', $item->bank_account_id);
            }

            $actual = $query->where(function ($q) {
                    $q->where(function ($inner) {
                        $inner->whereNotNull('booked_at')
                            ->whereBetween('booked_at', [$this->period_start, $this->period_end]);
                    })->orWhere(function ($or) {
                        $or->whereNull('booked_at')
                            ->whereBetween('created_at', [$this->period_start, $this->period_end]);
                    });
                })
                ->get(['amount'])
                ->sum(fn ($t) => abs((float) $t->amount));
        }

        $planned = (float) $this->planned_amount;
        $percent = $planned > 0 ? round($actual / $planned * 100, 1) : 0;

        $now = now()->startOfDay();
        $periodEnd = $this->period_end->copy()->endOfDay();

        if ($this->status === 'skipped') {
            // Don't change skipped periods
            return $this;
        }

        $status = 'pending';
        if ($actual > 0 && $percent >= 80) {
            $status = 'fulfilled';
        } elseif ($actual > 0) {
            $status = 'partial';
        } elseif ($now->gt($periodEnd) && $actual == 0) {
            $status = 'missed';
        }

        $this->update([
            'actual_amount' => $actual,
            'percent' => $percent,
            'status' => $status,
        ]);

        return $this;
    }
}
