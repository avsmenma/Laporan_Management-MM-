<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProduksiPks extends Model
{
    protected $table = 'produksi_pks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'sisa_awal' => 'decimal:2',
            'tbs_diterima_sdhari' => 'decimal:2',
            'tbs_diterima_sdbulan' => 'decimal:2',
            'tbs_diolah_sdhari' => 'decimal:2',
            'tbs_diolah_sdbulan' => 'decimal:2',
            'sisa_akhir' => 'decimal:2',
            'ms_sdhari' => 'decimal:2',
            'ms_sdbulan' => 'decimal:2',
            'is_sdhari' => 'decimal:2',
            'is_sdbulan' => 'decimal:2',
            'tidak_mengolah' => 'boolean',
        ];
    }
}
