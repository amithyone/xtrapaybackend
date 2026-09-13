<?php

namespace App\Models\Xtrapay;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XtrapayTransaction extends Model
{
    protected $table = 'xtrapay_transactions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'wallet_id', 'title', 'subtitle', 'occurred_at',
        'amount', 'type', 'status', 'category', 'reference', 'token',
        'bank', 'recipient', 'card_last4', 'card_usd_amount', 'note',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'amount' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(XtrapayUser::class, 'user_id');
    }

    public function toApiArray(): array
    {
        $at = $this->occurred_at;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'date' => $at?->format('Y-m-d') === now()->format('Y-m-d')
                ? 'Today - '.$at->format('d M Y')
                : $at?->format('d M Y'),
            'timestamp' => $at?->format('H:i'),
            'fullTime' => $at?->format('H:i:s'),
            'occurredAt' => $at?->toIso8601String(),
            'amount' => (float) $this->amount,
            'type' => $this->type,
            'status' => $this->status,
            'category' => $this->category,
            'reference' => $this->reference,
            'token' => $this->token,
            'bank' => $this->bank,
            'recipient' => $this->recipient,
            'cardLast4' => $this->card_last4,
            'cardUsdAmount' => $this->card_usd_amount,
            'note' => $this->note,
            'walletId' => $this->wallet_id,
        ];
    }
}
