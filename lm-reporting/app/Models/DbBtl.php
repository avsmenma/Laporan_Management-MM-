<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DbBtl extends Model
{
    public $timestamps = false;

    protected $table = 'db_btl';

    protected $guarded = [];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function klasifikasi(): BelongsTo
    {
        return $this->belongsTo(RefKlasifikasi::class, 'klasifikasi_code', 'code');
    }
}
