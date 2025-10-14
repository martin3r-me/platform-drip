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
    ];

    use Encryptable;

    protected $casts = [
        'matchers' => 'array',
        'defaults' => 'array',
    ];

    protected array $encryptable = [
        // falls sensible Vergleichswerte enthalten sind (z. B. IBAN in matchers/defaults), lieber gesondert halten
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

    public function scopeVisibleFor($query, \Platform\Core\Models\User $user)
    {
        return $query->where('team_id', $user->current_team_id);
    }
}


