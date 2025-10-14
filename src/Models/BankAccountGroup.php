<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;
use Platform\Core\Traits\Encryptable;

class BankAccountGroup extends Model
{
    use SoftDeletes, Encryptable;

    protected $table = 'drip_bank_account_groups';

    protected $fillable = [
        'uuid', 'team_id', 'user_id',
        'name', 'color', 'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    protected array $encryptable = [
        // keine sensiblen Felder standardmäßig
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'group_id');
    }

    // Alias für bessere Lesbarkeit
    public function accounts(): HasMany
    {
        return $this->bankAccounts();
    }

    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(
            BankTransaction::class,
            BankAccount::class,
            'group_id',
            'bank_account_id',
            'id',
            'id'
        )->orderByDesc('booked_at');
    }

    public function recurringPatterns(): HasMany
    {
        return $this->hasMany(RecurringPattern::class, 'bank_account_group_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(FinanceMetric::class, 'bank_account_group_id');
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


