<?php

namespace App\Models\Xtrapay;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XtrapayWallet extends Model
{
    protected $table = 'xtrapay_wallets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'name', 'kind', 'account_number', 'bank_name',
        'balance', 'subtitle', 'currency',
    ];

    protected $casts = ['balance' => 'float'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(XtrapayUser::class, 'user_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'accountNumber' => $this->account_number,
            'bankName' => $this->bank_name,
            'balance' => (float) $this->balance,
            'subtitle' => $this->subtitle,
            'currency' => $this->currency,
        ];
    }
}
