<?php

namespace Repay\Fee\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Merchant extends Model
{
    protected $fillable = ['name', 'business_id'];
    protected $table = 'merchants';
}
