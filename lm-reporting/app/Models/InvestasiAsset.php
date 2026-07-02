<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestasiAsset extends Model
{
    protected $table = 'investasi_asset';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'tahun_tanam' => 'integer',
            'period' => 'integer',
            'luas_ha' => 'decimal:2',
            'pokok' => 'decimal:2',
            'apc_start' => 'decimal:2',
            'acquisition' => 'decimal:2',
            'retirement' => 'decimal:2',
            'transfer' => 'decimal:2',
            'current_apc' => 'decimal:2',
            'impairment' => 'decimal:2',
            'reklas_debet' => 'decimal:2',
            'impair_awal' => 'decimal:2',
            'impair_pengurangan' => 'decimal:2',
            'curr_bk_val' => 'decimal:2',
        ];
    }
}
