<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;
use Platform\Core\Traits\Encryptable;

class RecurringPattern extends Model
{
    protected $table = 'drip_recurring_patterns';

    protected $fillable = [
        'uuid', 'team_id', 'user_id',
        'name', 'frequency', 'day_of_month', 'weekday', 'matchers', 'defaults',
        'bank_transaction_category_id', 'priority', 'is_active',
    ];

    use Encryptable;

    protected $casts = [
        // matchers und defaults werden via Encryptable als EncryptedJson gecastet
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    protected array $encryptable = [
        'matchers' => 'json',
        'defaults' => 'json',
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
        return $this->belongsTo(BankTransactionCategory::class, 'bank_transaction_category_id');
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(BankTransaction::class, 'drip_bank_transaction_recurring_pattern', 'recurring_pattern_id', 'bank_transaction_id');
    }

    // Scopes
    public function scopeForTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Ziel-Kategorie der Regel — FK-Spalte bevorzugt, defaults als Fallback. */
    public function targetCategoryId(): ?int
    {
        $id = $this->bank_transaction_category_id
            ?? (is_array($this->defaults) ? ($this->defaults['category_id'] ?? null) : null);

        return $id ? (int) $id : null;
    }

    public function scopeVisibleFor($query, \Platform\Core\Models\User $user)
    {
        return $query->where('team_id', $user->current_team_id);
    }
}


