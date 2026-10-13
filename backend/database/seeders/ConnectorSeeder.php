<?php

namespace Database\Seeders;

use App\Services\Connectors\ConnectorRegistry;
use Illuminate\Database\Seeder;

/** Katalogdaki her bağlantı için satır (açık) ve kapsam etiketleri. Tekrar çalıştırılabilir. */
class ConnectorSeeder extends Seeder
{
    public function run(ConnectorRegistry $registry): void
    {
        $registry->sync();
    }
}
