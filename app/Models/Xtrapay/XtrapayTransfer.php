<?php

namespace App\Models\Xtrapay;

use Illuminate\Database\Eloquent\Model;

class XtrapayTransfer extends Model
{
    protected $table = 'xtrapay_transfers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'wallet_id', 'step', 'amount', 'recipient_name',
        'bank_name', 'account_number', 'reference', 'narration',
        'init_time', 'processed_time', 'settled_time', 'is_complete',
    ];

    protected $casts = [
        'amount' => 'float',
        'is_complete' => 'boolean',
    ];

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'step' => (int) $this->step,
            'amount' => (float) $this->amount,
            'recipientName' => $this->recipient_name,
            'bankName' => $this->bank_name,
            'accountNumber' => $this->account_number,
            'reference' => $this->reference,
            'narration' => $this->narration,
            'initTime' => $this->init_time,
            'processedTime' => $this->processed_time,
            'settledTime' => $this->settled_time,
            'isComplete' => (bool) $this->is_complete,
        ];
    }
}
