<?php

namespace App\Domain\Import\Support;

use Maatwebsite\Excel\Concerns\ToArray;

class RawWorkbookImport implements ToArray
{
    public function array(array $array): array
    {
        return $array;
    }
}
