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
        'uuid', 'team_id', 'user_id', 'bank_account_id', 'category_id', 'recurring_pattern_id',
        'transaction_id', 'booking_date', 'booking_date_time', 'value_date', 'value_date_time', 'booked_at',
        'amount', 'currency', 'direction', 'status', 'metadata',
        'remittance_information', 'remittance_information_structured', 'remittance_information_structured_array',
        'remittance_information_unstructured', 'remittance_information_unstructured_array',
        'debtor_name', 'creditor_name', 'debtor_account_iban', 'creditor_account_iban',
        'debtor_agent', 'creditor_agent', 'transaction_type', 'bank_transaction_code',
        'proprietary_bank_transaction_code', 'internal_transaction_id', 'entry_reference',
        'end_to_end_id', 'mandate_id', 'merchant_category_code', 'check_id', 'creditor_id',
        'purpose_code', 'ultimate_creditor', 'ultimate_debtor', 'currency_exchange',
        'balance_after_transaction', 'additional_data_structured', 'additional_information',
        'additional_information_structured',
        // Legacy fields
        'counterparty_name', 'counterparty_iban', 'reference',
    ];

    protected $casts = [
        'booked_at' => 'date',
        'booking_date' => 'date',
        'booking_date_time' => 'datetime',
        'value_date' => 'date',
        'value_date_time' => 'datetime',
        'metadata' => 'array',
        'currency_exchange' => 'array',
        'additional_data_structured' => 'array',
        'remittance_information_structured_array' => 'array',
        'remittance_information_unstructured_array' => 'array',
    ];


    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->initializeEncryptable([
            // Beträge und Salden
            'amount' => 'decimal:4',
            'balance_after_transaction' => 'json', // Array mit verschlüsselten Werten
            
            // IBANs
            'counterparty_iban' => 'string',
            'debtor_account_iban' => 'string',
            'creditor_account_iban' => 'string',
            
            // Namen (personenbezogen)
            'counterparty_name' => 'string',
            'debtor_name' => 'string',
            'creditor_name' => 'string',
            'ultimate_creditor' => 'string',
            'ultimate_debtor' => 'string',
            
            // Verwendungszweck (kann persönlich sein)
            'reference' => 'string',
            'remittance_information' => 'string',
            'remittance_information_structured' => 'string',
            'remittance_information_unstructured' => 'string',
            
            // Zusätzliche Informationen
            'additional_information' => 'string',
            'additional_information_structured' => 'string',
        ]);
    }

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


