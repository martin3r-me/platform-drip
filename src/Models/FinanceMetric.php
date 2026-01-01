<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;
use Platform\Core\Traits\Encryptable;

class FinanceMetric extends Model
{
    use Encryptable;

    protected $table = 'drip_finance_metrics';

    protected $fillable = [
        'uuid', 'team_id', 'date', 'income', 'expenses', 'savings', 'balance', 'extras',
    ];

    protected $casts = [
        'date' => 'date',
        'extras' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->initializeEncryptable([
            'income' => 'decimal:4',
            'expenses' => 'decimal:4',
            'savings' => 'decimal:4',
            'balance' => 'decimal:4',
        ]);
    }

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


