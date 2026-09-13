<?php

namespace App\Models\Xtrapay;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class XtrapayUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'xtrapay_users';

    protected $fillable = [
        'full_name', 'email', 'phone', 'password', 'pin_hash',
        'address', 'city', 'state', 'date_of_birth', 'gender', 'tier',
        'id_type', 'id_number', 'kyc_status', 'agent_code', 'preferences',
        'daily_spent', 'daily_limit', 'single_txn_cap', 'transfer_cap', 'pos_float_cap',
        'overdraft_limit', 'flexible_savings', 'strict_savings', 'strict_auto_save',
        'card_frozen', 'xpoints_balance',
    ];

    protected $hidden = ['password', 'pin_hash', 'remember_token', 'id_number'];

    protected $casts = [
        'preferences' => 'array',
        'date_of_birth' => 'date',
        'strict_auto_save' => 'boolean',
        'card_frozen' => 'boolean',
        'daily_spent' => 'float',
        'daily_limit' => 'float',
        'single_txn_cap' => 'float',
        'transfer_cap' => 'float',
        'pos_float_cap' => 'float',
        'overdraft_limit' => 'float',
        'flexible_savings' => 'float',
        'strict_savings' => 'float',
        'xpoints_balance' => 'float',
    ];

    public function wallets(): HasMany
    {
        return $this->hasMany(XtrapayWallet::class, 'user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(XtrapayTransaction::class, 'user_id');
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(XtrapayBeneficiary::class, 'user_id');
    }

    public function toProfileArray(): array
    {
        $prefs = $this->preferences ?? [];

        return [
            'id' => (string) $this->id,
            'fullName' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'dateOfBirth' => optional($this->date_of_birth)->format('Y-m-d'),
            'gender' => $this->gender,
            'tier' => $this->tier,
            'kyc' => [
                'bvnMasked' => $this->id_type === 'bvn' && $this->id_number
                    ? substr($this->id_number, 0, 3).'*****'.substr($this->id_number, -4)
                    : null,
                'ninMasked' => $this->id_type === 'nin' && $this->id_number
                    ? substr($this->id_number, 0, 3).'*******'.substr($this->id_number, -4)
                    : null,
                'idType' => $this->id_type,
                'status' => $this->kyc_status,
            ],
            'agent' => $this->agent_code ? [
                'code' => $this->agent_code,
                'aggregator' => 'Lagos Island',
                'active' => true,
            ] : null,
            'preferences' => $prefs,
        ];
    }
}
