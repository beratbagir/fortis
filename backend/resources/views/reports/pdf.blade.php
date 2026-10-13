{{-- Rapor PDF şablonu (dompdf). Font DejaVu Sans: Türkçe karakterler için. Yatay A4. --}}
@php
    use App\Services\Reports\ReportTable;
    $small = count($table->columns) > 6;
    $groups = $table->groups();
@endphp
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>{{ $table->title }}</title>
<style>
    @page { margin: 18mm 12mm 16mm 12mm; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #111827; font-size: {{ $small ? '7.5pt' : '9pt' }}; }
    .head { width: 100%; border-bottom: 2px solid #0B2A4A; padding-bottom: 6px; margin-bottom: 10px; }
    .brand-row { border-collapse: collapse; }
    .logo { width: 20px; height: 20px; text-align: center; vertical-align: middle; background: #0B2A4A; color: #fff; font-weight: bold; font-size: 10pt; }
    .brand { font-weight: bold; color: #0B2A4A; padding-left: 6px; font-size: 10pt; vertical-align: middle; }
    h1 { font-size: 15pt; margin: 8px 0 2px; }
    .meta { color: #5B6676; font-size: 8pt; }
    .summary { border: 1px solid #C9D6E6; background: #EEF3F9; padding: 6px 8px; margin: 8px 0 10px; }
    .summary span { display: inline-block; margin-right: 14px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data thead { display: table-header-group; }
    table.data th { background: #E6EDF5; color: #0B2A4A; text-align: left; padding: 4px 5px; border-bottom: 1px solid #0B2A4A; }
    table.data td { padding: 3px 5px; border-bottom: 1px solid #E4E8EE; vertical-align: top; }
    table.data tr:nth-child(even) td { background: #F7F9FB; }
    .num { text-align: right; white-space: nowrap; }
    .empty { color: #5B6676; padding: 12px 0; }
</style>
</head>
<body>
<div class="head">
    <table class="brand-row"><tr><td class="logo">F</td><td class="brand">Fortis</td></tr></table>
    <h1>{{ $table->title }}</h1>
    <div class="meta">
        Oluşturulma: {{ $table->generatedAt->format('d.m.Y H:i') }} · {{ number_format(count($table->rows), 0, ',', '.') }} satır
        @foreach ($table->filters as $label => $value) · {{ $label }}: {{ $value }} @endforeach
    </div>
</div>

@if ($groups !== [])
    <div class="summary">
        <strong>{{ $table->columns[$table->groupBy]['label'] ?? $table->groupBy }}:</strong>
        @foreach ($groups as $group => $count)<span>{{ $group }}: {{ number_format($count, 0, ',', '.') }}</span>@endforeach
    </div>
@endif

@if (count($table->rows) === 0)
    <p class="empty">Bu filtreyle kayıt bulunamadı.</p>
@else
<table class="data">
    <thead>
        <tr>
            @foreach ($table->columns as $column)
                <th class="{{ in_array($column['type'], ['integer', 'number'], true) ? 'num' : '' }}">{{ $column['label'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($table->rows as $row)
            <tr>
                @foreach ($table->columns as $key => $column)
                    <td class="{{ in_array($column['type'], ['integer', 'number'], true) ? 'num' : '' }}">{{ ReportTable::text($row[$key] ?? null, $column['type']) }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
@endif
</body>
</html>
