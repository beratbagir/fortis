<?php

namespace App\Ai;

/**
 * APM'nin büyük JSON yanıtlarını Claude'a gidecek düz satırlara çevirir. Sadece burada tanımlı uçlar ve alanlar
 * seçilir (izin listesi): mürettebat adı, kullanıcı adı, iletişim bilgisi gibi alanlar hiç okunmaz.
 */
class ApmResultShaper
{
    /** query_apm ile sorgulanabilen uçlar. */
    public const OPERATIONS = [
        'GET /api/legs' => 'Uçuş bacakları: sefer no, kalkış/varış, planlı ve gerçekleşen saatler (UTC), rötar dakikası ve kodları, uçak, iptal, yolcu sayısı. Parametreler: dateFrom, dateTo (ISO, UTC), page.',
        'GET /api/leglogs' => 'Bacak değişiklik kayıtları: değişiklik türü (NEW/MOD/CNL/DEL), nedeni, zamanı, yeni rötar kodları. Parametreler: dateFrom, dateTo, pageNumber, pageSize.',
        'GET /api/iata-delay-codes' => 'IATA rötar kodları ve açıklamaları.',
        'GET /api/company-aircrafts' => 'Filodaki uçaklar: kod, tescil, tip, ana üs.',
        'POST /api/reports/legs/legsOnTimeAtDepartureReport' => 'Kalkışta zamanındalık (OTP) raporu. Sorgu: airportCode; gövde: sectorSelectionTimeRange, useSpecificDate, specificDate.',
        'POST /api/reports/legs/legsOnTimeAtArrivalReport' => 'Varışta zamanındalık (OTP) raporu. Sorgu: airportCode; gövde: sectorSelectionTimeRange, useSpecificDate, specificDate.',
    ];

    /**
     * Her uç için query_apm'in döndürdüğü düz alan adları (dönüştürücünün kendisinden türetilir, elle tutulmaz).
     * Architect ve agent talimatları APM'nin ham alan yollarını değil bunları kullanır.
     *
     * @return array<string, list<string>>
     */
    public function fields(): array
    {
        $samples = [
            'GET /api/legs' => ['legs' => ['content' => [[]]]],
            'GET /api/leglogs' => [['legLogList' => [[]]]],
            'GET /api/iata-delay-codes' => ['_embedded' => ['delayCodeDtoList' => [[]]]],
            'GET /api/company-aircrafts' => ['_embedded' => ['companyAircraftDtoList' => [[]]]],
            'POST /api/reports/legs/legsOnTimeAtDepartureReport' => ['_embedded' => ['legs' => [[]]]],
            'POST /api/reports/legs/legsOnTimeAtArrivalReport' => ['_embedded' => ['legs' => [[]]]],
        ];

        $fields = [];
        foreach ($samples as $operation => $sample) {
            [$method, $path] = explode(' ', $operation, 2);
            $fields[$operation] = $this->shape($method, $path, $sample)['columns'];
        }

        return $fields;
    }

    public function supports(string $method, string $operationPath): bool
    {
        return array_key_exists(strtoupper($method).' '.$operationPath, self::OPERATIONS);
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $response
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, total_rows: int}
     */
    public function shape(string $method, string $operationPath, array $response): array
    {
        return match (strtoupper($method).' '.$operationPath) {
            'GET /api/legs' => $this->rows(
                data_get($response, 'legs.content', []),
                (int) ($response['totalNumberOfItems'] ?? 0),
                fn (array $leg) => [
                    'leg_id' => data_get($leg, 'legId'),
                    'flight_no' => trim(data_get($leg, 'flightNumberInfo.designator', '').data_get($leg, 'flightNumberInfo.flightNumber', '')),
                    'dep' => data_get($leg, 'airportsInfo.scheduledDepartureAirportCode'),
                    'arr' => data_get($leg, 'airportsInfo.scheduledArrivalAirportCode'),
                    'std_utc' => data_get($leg, 'flightTimes.scheduledTimeDeparture'),
                    'sta_utc' => data_get($leg, 'flightTimes.scheduledTimeArrival'),
                    'atd_utc' => data_get($leg, 'flightTimes.actualTimeDeparture'),
                    'ata_utc' => data_get($leg, 'flightTimes.actualTimeArrival'),
                    'dep_delay_min' => self::delaySum($leg, 'delaysInfo.departureDelay'),
                    'delay_codes' => self::delayCodes($leg, 'delaysInfo.departureDelay', 'delayCode'),
                    'delay_reasons' => self::delayCodes($leg, 'delaysInfo.departureDelay', 'delayReason'),
                    'aircraft_reg' => data_get($leg, 'aircraftInfo.actualAircraftRegistration'),
                    'aircraft_type' => data_get($leg, 'aircraftInfo.actualAircraftTypeCode') ?? data_get($leg, 'aircraftInfo.aircraftTypeCode'),
                    'cancelled' => (bool) data_get($leg, 'statusInfo.cancelled', false),
                    'cancel_reason' => data_get($leg, 'changeReasons.cancellationReason'),
                    'pax_total' => array_sum(array_map(fn (string $c) => (int) data_get($leg, "passengerInfo.pax{$c}", 0), ['F', 'C', 'W', 'Y'])),
                    'pax_infant' => data_get($leg, 'passengerInfo.paxInfant'),
                ],
            ),
            'GET /api/leglogs' => $this->legLogs(array_is_list($response) ? $response : []),
            'GET /api/iata-delay-codes' => $this->rows(
                data_get($response, '_embedded.delayCodeDtoList', []),
                null,
                fn (array $code) => [
                    'code' => data_get($code, 'alphanumericCode'),
                    'business_area' => data_get($code, 'businessArea'),
                    'description' => data_get($code, 'description'),
                ],
            ),
            'GET /api/company-aircrafts' => $this->rows(
                data_get($response, '_embedded.companyAircraftDtoList', []),
                null,
                fn (array $aircraft) => [
                    'aircraft_code' => data_get($aircraft, 'aircraftCode'),
                    'registration' => data_get($aircraft, 'registration'),
                    'aircraft_type' => data_get($aircraft, 'aircraftTypeCode'),
                    'aircraft_name' => data_get($aircraft, 'aircraftName'),
                    'base' => data_get($aircraft, 'followup.aircraftBase'),
                    'landing_category' => data_get($aircraft, 'landingCategory'),
                ],
            ),
            'POST /api/reports/legs/legsOnTimeAtDepartureReport', 'POST /api/reports/legs/legsOnTimeAtArrivalReport' => $this->rows(
                self::firstEmbeddedList($response),
                null,
                fn (array $leg) => [
                    'leg_id' => data_get($leg, 'legId'),
                    'flight_no' => data_get($leg, 'flightDesignator'),
                    'dep' => data_get($leg, 'departureAirport'),
                    'arr' => data_get($leg, 'arrivalAirport'),
                    'scheduled_utc' => data_get($leg, 'scheduleDepartureTimeUtc') ?? data_get($leg, 'scheduleArrivalTimeUtc'),
                    'actual_utc' => data_get($leg, 'actualDepartureTimeUtc') ?? data_get($leg, 'actualArrivalTimeUtc'),
                    'on_time_status' => data_get($leg, 'onTimeStatus'),
                    'event_status' => data_get($leg, 'eventStatus'),
                    'aircraft_reg' => data_get($leg, 'aircraftRegistration'),
                ],
            ),
            default => throw new \InvalidArgumentException("Dönüştürücü yok: {$method} {$operationPath}"),
        };
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $map
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, total_rows: int}
     */
    private function rows(mixed $items, ?int $total, callable $map): array
    {
        $rows = array_values(array_map($map, array_filter(is_array($items) ? $items : [], 'is_array')));

        return [
            'columns' => $rows === [] ? [] : array_keys($rows[0]),
            'rows' => $rows,
            'total_rows' => max($total ?? 0, count($rows)),
        ];
    }

    /**
     * @param  list<mixed>  $flights
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, total_rows: int}
     */
    private function legLogs(array $flights): array
    {
        $items = [];
        foreach ($flights as $flight) {
            foreach ((array) data_get($flight, 'legLogList', []) as $log) {
                if (is_array($log)) {
                    $items[] = ['flight' => $flight, 'log' => $log];
                }
            }
        }

        return $this->rows($items, null, fn (array $item) => [
            'leg_id' => data_get($item, 'log.legId'),
            'flight_no' => trim(data_get($item, 'flight.flightDesignator', '').data_get($item, 'flight.flightNumber', '')),
            'change_type' => data_get($item, 'log.changeType'),
            'last_change_reason' => data_get($item, 'log.lastChangeReason'),
            'changed_at_utc' => data_get($item, 'log.creationDate'),
            'new_dep_delay_min' => self::delaySum($item['log'], 'departureDelayLog', '.newValue'),
            'new_delay_codes' => self::delayCodes($item['log'], 'departureDelayLog', 'delayCode', '.newValue'),
        ]);
    }

    /** @param  array<string, mixed>  $source */
    private static function delaySum(array $source, string $prefix, string $suffix = ''): ?int
    {
        $values = array_filter(array_map(fn (int $i) => data_get($source, "{$prefix}{$i}{$suffix}.delayDurationInMinutes"), range(1, 4)), 'is_numeric');

        return $values === [] ? null : (int) array_sum($values);
    }

    /** @param  array<string, mixed>  $source */
    private static function delayCodes(array $source, string $prefix, string $field, string $suffix = ''): ?string
    {
        $values = array_filter(array_map(fn (int $i) => data_get($source, "{$prefix}{$i}{$suffix}.{$field}"), range(1, 4)), fn ($v) => is_string($v) && $v !== '');

        return $values === [] ? null : implode(', ', $values);
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $response
     * @return list<mixed>
     */
    private static function firstEmbeddedList(array $response): array
    {
        foreach ((array) ($response['_embedded'] ?? []) as $list) {
            if (is_array($list) && array_is_list($list)) {
                return $list;
            }
        }

        return [];
    }
}
