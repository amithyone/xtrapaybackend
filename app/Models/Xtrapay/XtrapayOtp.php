<?php

namespace App\Models\Xtrapay;

use Illuminate\Database\Eloquent\Model;

class XtrapayOtp extends Model
{
    protected $table = 'xtrapay_otps';

    protected $fillable = ['destination', 'purpose', 'code', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];
}
