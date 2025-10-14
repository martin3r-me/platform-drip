<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Uid\UuidV7;
use Platform\Core\Traits\Encryptable;

class Institution extends Model
{
    use SoftDeletes, Encryptable;

    protected $table = 'drip_institutions';

    protected $fillable = [
        'uuid', 'team_id', 'user_id',
        'external_id', 'name', 'country', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected array $encryptable = [
        // z. B. BIC/Logo sind nicht kritisch; external_id optional verschlüsseln, falls sensibel
        // 'external_id' => 'string',
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

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'institution_id');
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


