<?php

namespace App\Models\Xtrapay;

use Illuminate\Database\Eloquent\Model;

class XtrapayBank extends Model
{
    protected $table = 'xtrapay_banks';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'code'];
}
