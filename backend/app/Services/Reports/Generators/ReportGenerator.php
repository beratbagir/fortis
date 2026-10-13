<?php

namespace App\Services\Reports\Generators;

use App\Services\Reports\ReportTable;

interface ReportGenerator
{
    /** Tabloyu verilen dosya yoluna yazar. */
    public function write(ReportTable $table, string $path): void;
}
