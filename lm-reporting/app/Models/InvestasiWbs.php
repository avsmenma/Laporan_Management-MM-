<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestasiWbs extends Model
{
    protected $table = 'investasi_wbs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'nilai' => 'decimal:2',
            'tahun_tanam' => 'integer',
            'period' => 'integer',
        ];
    }
}
