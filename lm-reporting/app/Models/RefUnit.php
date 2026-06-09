<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RefUnit extends Model
{
    public $timestamps = false;

    protected $table = 'ref_unit';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function komoditis(): HasMany
    {
        return $this->hasMany(RefUnitKomoditi::class, 'unit_id');
    }

    public function reportLm14(): HasMany
    {
        return $this->hasMany(ReportLm14::class, 'unit_id');
    }

    public function reportLm13(): HasMany
    {
        return $this->hasMany(ReportLm13::class, 'unit_id');
    }

    public function reportLm16(): HasMany
    {
        return $this->hasMany(ReportLm16::class, 'unit_id');
    }
}
