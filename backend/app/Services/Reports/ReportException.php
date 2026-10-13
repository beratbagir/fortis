<?php

namespace App\Services\Reports;

use RuntimeException;

/** Rapor isteği geçersiz (katalog dışı kaynak, sütun veya format). */
class ReportException extends RuntimeException {}
