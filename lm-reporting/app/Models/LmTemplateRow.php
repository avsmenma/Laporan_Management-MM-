<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmTemplateRow extends Model
{
    public $timestamps = false;

    protected $table = 'lm_template_row';

    protected $guarded = [];

    public function reportLm14(): HasMany
    {
        return $this->hasMany(ReportLm14::class, 'template_id');
    }

    public function reportLm13(): HasMany
    {
        return $this->hasMany(ReportLm13::class, 'template_id');
    }

    public function reportLm16(): HasMany
    {
        return $this->hasMany(ReportLm16::class, 'template_id');
    }
}
