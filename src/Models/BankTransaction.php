<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;
use Platform\Core\Traits\Encryptable;

class BankTransaction extends Model
{
    use SoftDeletes, Encryptable;

    protected $table = 'drip_bank_transactions';

    protected $fillable = [
        'uuid', 'team_id', 'user_id', 'bank_account_id', 'category_id',
        'booked_at', 'counterparty_name', 'counterparty_iban', 'reference',
        'amount', 'currency', 'direction', 'status', 'metadata',
    ];

    protected $casts = [
        'booked_at' => 'date',
        'metadata' => 'array',
        'amount' => 'decimal:4',
    ];

    protected array $encryptable = [
        'counterparty_iban' => 'string',
        'reference' => 'string',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            do {
                $uuid = UuidV7::generate();
            } while (self::where('uuid', $uuid)->exists());

            $model->uuid = $model->uuid ?: $uuid;
            if (! $model->user_id && $model->bankAccount) {
                $model->user_id = $model->bankAccount->user_id;
            }
            if (! $model->team_id && $model->bankAccount) {
                $model->team_id = $model->bankAccount->team_id;
            }
        });
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BankTransactionCategory::class, 'category_id');
    }

    public function recurringPatterns(): BelongsToMany
    {
        return $this->belongsToMany(RecurringPattern::class, 'drip_bank_transaction_recurring_pattern', 'bank_transaction_id', 'recurring_pattern_id');
    }

    public function sourceTransfer(): HasOne
    {
        return $this->hasOne(InternalTransfer::class, 'source_transaction_id');
    }

    public function targetTransfer(): HasOne
    {
        return $this->hasOne(InternalTransfer::class, 'target_transaction_id');
    }

    // Scopes
    public function scopeForTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeVisibleFor($query, \Platform\Core\Models\User $user)
    {
        $teamId = $user->current_team_id;
        return $query->where('team_id', $teamId);
    }
}


