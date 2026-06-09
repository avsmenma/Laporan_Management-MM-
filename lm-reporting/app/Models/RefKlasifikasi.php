<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RefKlasifikasi extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'ref_klasifikasi';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = [];

    public function dbWbs(): HasMany
    {
        return $this->hasMany(DbWbs::class, 'klasifikasi_code', 'code');
    }

    public function dbBtl(): HasMany
    {
        return $this->hasMany(DbBtl::class, 'klasifikasi_code', 'code');
    }
}
