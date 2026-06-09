<?php

namespace App\Domain\Import;

class ImportResult
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly int $rowCount,
        public readonly array $errors = [],
    ) {
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }
}
