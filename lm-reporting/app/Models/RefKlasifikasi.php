<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefKlasifikasi extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'ref_klasifikasi';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = [];
}
