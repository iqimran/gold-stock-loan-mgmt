<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $dataset->title }}</title>
    <style>
        @page { margin: 18mm 12mm 16mm 12mm; }
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 8.5pt; color: #111; }
        .shop { text-align: center; margin-bottom: 6px; }
        .shop h1 { font-size: 13pt; margin: 0; }
        .shop p { margin: 1px 0; }
        h2 { font-size: 12pt; margin: 8px 0 4px; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .meta td { padding: 1px 6px 1px 0; vertical-align: top; }
        .meta td.label { color: #555; width: 22%; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #eee; border-bottom: 1px solid #555; padding: 4px; text-align: left; }
        table.data td { border-bottom: 1px solid #ddd; padding: 3px 4px; vertical-align: top; }
        table.data .num { text-align: right; white-space: nowrap; }
        table.data tr.total td { font-weight: bold; border-bottom: none; background: #f6f6f6; }
        table.data tr.total.first td { border-top: 1.5px solid #555; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .empty { text-align: center; color: #777; padding: 12px; }
    </style>
</head>
<body>
    <div class="shop">
        <h1>{{ $shop['name'] }}</h1>
        @if ($shop['address'])<p>{{ $shop['address'] }}</p>@endif
        @if ($shop['phone'])<p>Tel: {{ $shop['phone'] }}</p>@endif
    </div>

    <h2>{{ $dataset->title }}</h2>

    <table class="meta">
        @foreach ($dataset->filters as $label => $value)
            <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
        <tr><td class="label">Generated</td><td>{{ $generatedAt }}@if ($generatedBy) by {{ $generatedBy }}@endif · {{ count($dataset->rows) }} row(s)</td></tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                @foreach ($dataset->columns as $column)
                    <th @class(['num' => $column->isNumeric()])>{{ $column->label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($dataset->rows as $row)
                <tr>
                    @foreach ($dataset->columns as $column)
                        <td @class(['num' => $column->isNumeric()])>{{ $column->display($row[$column->key] ?? null) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="empty" colspan="{{ count($dataset->columns) }}">No records match these filters.</td></tr>
            @endforelse

            @foreach ($dataset->totals as $index => $total)
                <tr @class(['total', 'first' => $index === 0])>
                    @foreach ($dataset->columns as $position => $column)
                        @if ($position === 0)
                            <td>{{ $total['label'] }}</td>
                        @else
                            <td @class(['num' => $column->isNumeric()])>{{ array_key_exists($column->key, $total['values']) ? $column->display($total['values'][$column->key]) : '' }}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
