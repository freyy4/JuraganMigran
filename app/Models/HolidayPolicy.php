<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayPolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['scope' => 'array', 'rules' => 'array', 'active' => 'boolean'];
    }
}
