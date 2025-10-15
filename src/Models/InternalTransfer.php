<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;

class InternalTransfer extends Model
{
    use SoftDeletes;

    protected $table = 'drip_internal_transfers';

    protected $fillable = [
        'uuid', 'team_id', 'user_id',
        'from_account_id', 'to_account_id', 'source_transaction_id', 'target_transaction_id',
        'transferred_at', 'amount', 'currency', 'reference',
    ];

    protected $casts = [
        'transferred_at' => 'date',
        'amount' => 'decimal:4',
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

    public function source(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'source_transaction_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'target_transaction_id');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'to_account_id');
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


