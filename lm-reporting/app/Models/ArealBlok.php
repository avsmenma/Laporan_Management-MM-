<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArealBlok extends Model
{
    protected $table = 'areal_blok';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'luas_tanam' => 'decimal:2',
            'luas_ha' => 'decimal:2',
            'tahun_tanam' => 'integer',
            'total_pokok' => 'integer',
            'total_pokok_produktif' => 'integer',
        ];
    }
}
