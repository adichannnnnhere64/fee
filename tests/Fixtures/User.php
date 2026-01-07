<?php

namespace Repay\Fee\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = ['name', 'email'];

    protected $table = 'users';
}
