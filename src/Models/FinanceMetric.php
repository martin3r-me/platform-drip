<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;

class FinanceMetric extends Model
{
    protected $table = 'drip_finance_metrics';

    protected $fillable = [
        'uuid', 'team_id', 'date', 'income', 'expenses', 'savings', 'balance', 'extras',
    ];

    protected $casts = [
        'date' => 'date',
        'income' => 'decimal:4',
        'expenses' => 'decimal:4',
        'savings' => 'decimal:4',
        'balance' => 'decimal:4',
        'extras' => 'array',
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
}


