<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportLm14 extends Model
{
    public $timestamps = false;

    protected $table = 'report_lm14';

    protected $guarded = [];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(RefUnit::class, 'unit_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LmTemplateRow::class, 'template_id');
    }
}
