<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;
use Platform\Core\Traits\Encryptable;

class BankAccountBalance extends Model
{
    use Encryptable;

    protected $table = 'drip_bank_account_balances';

    protected $fillable = [
        'uuid', 'team_id', 'bank_account_id',
        'balance_type', 'amount', 'currency', 'retrieved_at',
        'as_of_date', 'balance', // Legacy fields
    ];

    protected $casts = [
        'as_of_date' => 'date',
        'retrieved_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->initializeEncryptable([
            'balance' => 'decimal:4',
            'amount' => 'decimal:4',
        ]);
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            do {
                $uuid = UuidV7::generate();
            } while (self::where('uuid', $uuid)->exists());

            $model->uuid = $model->uuid ?: $uuid;
            $model->team_id ??= $model->account?->team_id;
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    // Scopes
    public function scopeForTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeVisibleFor($query, \Platform\Core\Models\User $user)
    {
        return $query->where('team_id', $user->current_team_id);
    }
}


