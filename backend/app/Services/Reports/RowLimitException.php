<?php

namespace App\Services\Reports;

use RuntimeException;

/** Rapor REPORT_MAX_ROWS sınırını aşıyor; üretilmez. */
class RowLimitException extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct('Rapor en fazla '.number_format($limit, 0, ',', '.').' satır içerebilir; bu istek daha fazlasını döndürüyor. Tarih aralığını veya filtreyi daraltın.');
    }
}
