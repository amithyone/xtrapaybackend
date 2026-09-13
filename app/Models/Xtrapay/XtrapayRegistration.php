<?php

namespace App\Models\Xtrapay;

use Illuminate\Database\Eloquent\Model;

class XtrapayRegistration extends Model
{
    protected $table = 'xtrapay_registrations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'full_name', 'email', 'phone', 'password', 'kyc', 'status',
    ];

    protected $casts = ['kyc' => 'array'];
}
