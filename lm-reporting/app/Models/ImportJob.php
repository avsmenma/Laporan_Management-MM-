<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    protected $table = 'import_jobs';

    protected $guarded = [];

    protected $attributes = [
        'status'    => 'queued',
        'total'     => 0,
        'processed' => 0,
        'row_count' => 0,
    ];

    protected function casts(): array
    {
        return ['total' => 'integer', 'processed' => 'integer', 'row_count' => 'integer'];
    }
}
