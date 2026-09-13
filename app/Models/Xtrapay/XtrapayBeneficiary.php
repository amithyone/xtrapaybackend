<?php

namespace App\Models\Xtrapay;

use Illuminate\Database\Eloquent\Model;

class XtrapayBeneficiary extends Model
{
    protected $table = 'xtrapay_beneficiaries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'name', 'initials', 'bank', 'account_number', 'tier', 'color_class',
    ];

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'initials' => $this->initials,
            'bank' => $this->bank,
            'accountNumber' => $this->account_number,
            'tier' => $this->tier,
            'colorClass' => $this->color_class,
        ];
    }
}
