<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProduksiCpoInti extends Model
{
    protected $table = 'produksi_cpo_inti';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'ms_bulan_ini' => 'decimal:2',
            'is_bulan_ini' => 'decimal:2',
            'produksi_bulan_ini' => 'decimal:2',
            'ms_sd' => 'decimal:2',
            'is_sd' => 'decimal:2',
            'produksi_sd' => 'decimal:2',
        ];
    }
}
