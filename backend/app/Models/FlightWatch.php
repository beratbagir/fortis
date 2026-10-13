<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Olay tetikleyicileri için APM uçuş bacağının son görülen hali (rötar, iptal). Sadece operasyonel veri.
 */
#[Table('flight_watch', timestamps: false)]
#[Fillable(['leg_id', 'flight_no', 'dep', 'arr', 'std_utc', 'dep_delay_min', 'cancelled', 'seen_at'])]
class FlightWatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cancelled' => 'boolean', 'dep_delay_min' => 'integer', 'seen_at' => 'datetime'];
    }
}
