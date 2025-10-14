<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;

class BankTransactionCategory extends Model
{
    use SoftDeletes;

    protected $table = 'drip_bank_transaction_categories';

    protected $fillable = [
        'uuid', 'team_id', 'user_id', 'parent_id',
        'name', 'slug', 'color', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
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

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'category_id');
    }
}


