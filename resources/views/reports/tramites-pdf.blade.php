<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>REPORTE DE REGISTRO DE TRÁMITES</title>
    <style>
        @page { margin: 22px 24px 30px; }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #1c1917;
            font-size: 8.5px;
            margin: 0;
        }
        h1 {
            font-size: 15px;
            letter-spacing: .4px;
            margin: 0 0 2px;
            color: #a50e24;
        }
        .subtitle {
            font-size: 8px;
            color: #78716c;
            margin-bottom: 9px;
        }
        .meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 9px;
        }
        .meta td {
            padding: 2px 6px 2px 0;
            vertical-align: top;
        }
        .meta .label {
            width: 92px;
            color: #57534e;
            font-weight: bold;
            white-space: nowrap;
        }
        table.data {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        table.data th {
            background: #44403c;
            color: #ffffff;
            font-size: 7.5px;
            text-align: left;
            padding: 4px 4px;
            text-transform: uppercase;
            letter-spacing: .2px;
        }
        table.data td {
            border-bottom: 1px solid #e7e5e4;
            padding: 4px 4px;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        table.data thead { display: table-header-group; }
        table.data tr { page-break-inside: avoid; }
        table.data tbody tr:nth-child(even) td {
            background: #fafaf9;
        }
        .center { text-align: center; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        .badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
        }
        .badge-on { background: #dcfce7; color: #166534; }
        .badge-off { background: #e7e5e4; color: #57534e; }
        .badge-procesado { background: #fef3c7; color: #92400e; }
        .badge-recepcionado { background: #dbeafe; color: #1e40af; }
        .empty {
            text-align: center;
            color: #78716c;
            padding: 18px;
        }
        .footer {
            margin-top: 10px;
            border-top: 1px solid #e7e5e4;
            padding-top: 5px;
            color: #78716c;
            font-size: 7.5px;
        }
    </style>
</head>
<body>
    <h1>REPORTE DE REGISTRO DE TRÁMITES</h1>
    <div class="subtitle">Sistema de registro de trámites</div>

    <table class="meta">
        <tr>
            <td class="label">Usuario:</td>
            <td>{{ $usuario }}</td>
        </tr>
        <tr>
            <td class="label">Periodo:</td>
            <td>De {{ $desde }} a {{ $hasta }}</td>
        </tr>
        <tr>
            <td class="label">Total de registros:</td>
            <td>{{ $total }} ({{ $activos }} activos)</td>
        </tr>
        @if ($search)
            <tr>
                <td class="label">Búsqueda:</td>
                <td>{{ $search }}</td>
            </tr>
        @endif
    </table>

    <table class="data">
        <colgroup>
            <col style="width: 8%;">
            <col style="width: 6%;">
            <col style="width: 23%;">
            <col style="width: 12%;">
            <col style="width: 9%;">
            <col style="width: 18%;">
            <col style="width: 10%;">
            <col style="width: 8%;">
            <col style="width: 6%;">
        </colgroup>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th class="{{ in_array($header, ['Fecha', 'Hora', 'Nro. placa / póliza', 'Ticket', 'Estado', 'Activo']) ? 'center' : '' }}">
                        {{ $header }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="center">{{ $row[0] }}</td>
                    <td class="center">{{ $row[1] }}</td>
                    <td>{{ $row[2] }}</td>
                    <td class="center mono">{{ $row[3] }}</td>
                    <td class="center mono">{{ $row[4] }}</td>
                    <td>{{ $row[5] ?: '—' }}</td>
                    <td>{{ $row[6] }}</td>
                    <td class="center">
                        <span class="badge {{ $row[7] === 'Recepcionado' ? 'badge-recepcionado' : 'badge-procesado' }}">{{ $row[7] }}</span>
                    </td>
                    <td class="center">
                        <span class="badge {{ $row[8] === 'Activo' ? 'badge-on' : 'badge-off' }}">{{ $row[8] }}</span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}" class="empty">No hay registros en el periodo seleccionado.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Generado el {{ $generadoEl }} · {{ count($rows) }} registro(s)
    </div>
</body>
</html>
