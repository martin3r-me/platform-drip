<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;

class BudgetItem extends Model
{
    use SoftDeletes;

    protected $table = 'drip_budget_items';

    protected $fillable = [
        'uuid', 'team_id', 'user_id', 'category_id',
        'name', 'direction', 'amount', 'frequency',
        'day_of_month', 'start_date', 'end_date',
        'is_active', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'day_of_month' => 'integer',
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

    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Normalize budget amount to monthly basis.
     */
    public function monthlyAmount(): float
    {
        $amount = (float) $this->amount;

        return match ($this->frequency) {
            'weekly' => $amount * 4.33,
            'monthly' => $amount,
            'quarterly' => $amount / 3,
            'yearly' => $amount / 12,
            default => $amount,
        };
    }
}
