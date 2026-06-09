<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    public $timestamps = false;

    protected $table = 'batch';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function dbWbs(): HasMany
    {
        return $this->hasMany(DbWbs::class, 'batch_id');
    }

    public function dbBtl(): HasMany
    {
        return $this->hasMany(DbBtl::class, 'batch_id');
    }

    public function dbPks(): HasMany
    {
        return $this->hasMany(DbPks::class, 'batch_id');
    }

    public function alokasiProduksi(): HasMany
    {
        return $this->hasMany(AlokasiProduksi::class, 'batch_id');
    }

    public function pksBiaya(): HasMany
    {
        return $this->hasMany(PksBiaya::class, 'batch_id');
    }

    public function pksProduksi(): HasMany
    {
        return $this->hasMany(PksProduksi::class, 'batch_id');
    }

    public function reportLm14(): HasMany
    {
        return $this->hasMany(ReportLm14::class, 'batch_id');
    }

    public function reportLm13(): HasMany
    {
        return $this->hasMany(ReportLm13::class, 'batch_id');
    }

    public function reportLm16(): HasMany
    {
        return $this->hasMany(ReportLm16::class, 'batch_id');
    }

    public function importUploadLogs(): HasMany
    {
        return $this->hasMany(ImportUploadLog::class, 'batch_id');
    }
}
