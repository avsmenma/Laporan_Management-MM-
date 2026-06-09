<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefUnitKomoditi extends Model
{
    public $timestamps = false;

    protected $table = 'ref_unit_komoditi';

    protected $guarded = [];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(RefUnit::class, 'unit_id');
    }
}
