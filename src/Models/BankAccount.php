<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;
use Platform\Core\Traits\Encryptable;

class BankAccount extends Model
{
    use SoftDeletes, Encryptable;

    protected $table = 'drip_bank_accounts';

    protected $fillable = [
        'uuid', 'team_id', 'user_id', 'institution_id', 'group_id', 'external_id',
        'name', 'iban', 'bban', 'bic', 'product', 'currency', 'initial_balance', 'opened_at', 'closed_at', 
        'last_details_synced_at', 'last_transactions_synced_at', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'opened_at' => 'date',
        'closed_at' => 'date',
        'last_details_synced_at' => 'datetime',
        'last_transactions_synced_at' => 'datetime',
    ];

    protected function initializeEncryptable(): void
    {
        // Feldliste für Verschlüsselung setzen und Casts registrieren
        $this->encryptable = [
            'iban' => 'string',
            'initial_balance' => 'decimal:4',
        ];

        if (!property_exists($this, 'casts') || !is_array($this->casts)) {
            $this->casts = [];
        }

        foreach ($this->encryptable as $field => $type) {
            if ($type === 'json') {
                $this->casts[$field] = \Platform\Core\Casts\EncryptedJson::class;
            } elseif ($type === 'decimal' || str_starts_with($type, 'decimal:')) {
                $decimals = 2;
                if (str_contains($type, ':')) {
                    $decimals = (int) explode(':', $type)[1];
                }
                $this->casts[$field] = new \Platform\Core\Casts\EncryptedDecimal($decimals);
            } else {
                $this->casts[$field] = \Platform\Core\Casts\EncryptedString::class;
            }
        }
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BankAccountGroup::class, 'group_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(FinanceMetric::class, 'bank_account_id');
    }

    public function recurringPatterns(): HasMany
    {
        return $this->hasMany(RecurringPattern::class, 'bank_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'bank_account_id')->orderByDesc('booked_at');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(BankAccountBalance::class, 'bank_account_id');
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


