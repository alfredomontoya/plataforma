<?php

namespace App\Services;

use App\Models\Report;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use App\Support\GdBarChart;
use App\Support\GdPieChart;
use App\Support\GdTrendChart;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Informes .docx (docs 03 § Informes): cálculo periodo+previo, render con
 * placeholders + tablas + tortas PNG base64→imagen, preview sin guardar.
 */
class ReportService
{
    public const DIR = 'reports';

    public function __construct(
        private DashboardService $dash,
        private TemplateService $templates,
        private TotalService $totals,
    ) {}

    /** Periodo + agregados según modo (incluye total diario para las marcas nuevas). */
    public function compute(string $mode, ?string $date, ?string $weekStart, ?string $from = null, ?string $to = null): array
    {
        if ($mode === 'DAY') {
            $day = BusinessDay::parseUtcDay($date);
            $agg = $this->dash->day($date);

            return [
                'mode' => 'DAY',
                'start' => $day,
                'end' => $day->copy()->addDay(),
                'ingreso' => $agg['ingreso'],
                'entrega' => $agg['entrega'],
                'totalIngreso' => $agg['totalIngreso'],
                'totalEntrega' => $agg['totalEntrega'],
                'totals' => $this->totalsPayload($day->toDateString(), $day->toDateString(), true),
            ];
        }

        if ($mode === 'RANGE') {
            $start = BusinessDay::parseUtcDay($from);
            $end = BusinessDay::parseUtcDay($to)->addDay();
            $agg = $this->dash->range($from, $to); // valida rango (from<=to, máx 93 días)

            return [
                'mode' => 'RANGE',
                'start' => $start,
                'end' => $end,
                'ingreso' => $agg['ingresoByService'],
                'entrega' => $agg['entregaByService'],
                'totalIngreso' => $agg['totalIngreso'],
                'totalEntrega' => $agg['totalEntrega'],
                'totals' => $this->totalsPayload($from, $to, false),
            ];
        }

        $start = BusinessDay::weekStart(BusinessDay::parseUtcDay($weekStart));
        $end = $start->copy()->addDays(7);

        return [
            'mode' => 'WEEK',
            'start' => $start,
            'end' => $end,
            'ingreso' => $this->dash->serviceTotals($start, $end, 'INGRESO'),
            'entrega' => $this->dash->serviceTotals($start, $end, 'ENTREGA'),
            'totalIngreso' => $this->dash->range(
                $start->toDateString(), $end->copy()->subDay()->toDateString()
            )['totalIngreso'],
            'totalEntrega' => $this->dash->range(
                $start->toDateString(), $end->copy()->subDay()->toDateString()
            )['totalEntrega'],
            'totals' => $this->totalsPayload($start->toDateString(), $end->copy()->subDay()->toDateString(), false),
        ];
    }

    /**
     * Agregados del total diario: desglose por trámite + serie día a día.
     * En modo día la serie cubre los 7 días previos para una tendencia útil.
     */
    private function totalsPayload(string $from, string $to, bool $weekBack): array
    {
        $agg = $this->totals->range($from, $to);
        $trendFrom = $from;
        if ($weekBack) {
            $trendFrom = BusinessDay::parseUtcDay($from)->subDays(6)->toDateString();
        }

        return [
            'byTramite' => $agg['byTramite'],
            'total' => $agg['total'],
            'daily' => $this->totals->daily($trendFrom, $to),
        ];
    }

    public function fileName(string $mode, Carbon $start, Carbon $end, string $nro): string
    {
        $kind = match ($mode) {
            'DAY' => 'diario',
            'RANGE' => 'rango',
            default => 'semanal',
        };
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $nro);

        return "informe_{$kind}_{$start->format('Y-m-d')}_a_{$end->format('Y-m-d')}_{$safe}_".time().'.docx';
    }

    public function renderTo(string $templatePath, array $computed, array $meta, User $generator, string $outputPath, array $charts = []): void
    {
        // Las plantillas institucionales usan {VAR} y párrafos {TABLA_*} como
        // ancla de tablas (se inyectan como w:tbl real en una copia temporal).
        // La plantilla simple del sistema usa ${VAR} + filas clonables.
        [$workingPath, $cleanup, $skipIng, $skipEnt] = $this->injectMarkerTables($templatePath, $computed);
        $this->insertChartCaptions($workingPath, $computed);
        $tp = new TemplateProcessor($workingPath);
        if (! $this->usesDollarMacros($templatePath)) {
            $tp->setMacroChars('{', '}');
        }
        $tp->setValue('FECHA', BusinessDay::displayLong($meta['now']));
        $tp->setValue('NRO_CI', $meta['nroCI']);
        $tp->setValue('DIRIGIDO_A', $meta['dirigidoA']);
        $tp->setValue('PUESTO_DIRIGIDO_A', $meta['puestoDirigidoA']);
        $tp->setValue('REMITENTE', trim("{$generator->title} {$generator->fullName}"));
        $tp->setValue('PUESTO_REMITENTE', $generator->activePosition?->position ?? '');
        $tp->setValue('NRO_SEMANA', $computed['mode'] === 'WEEK' ? (string) $computed['start']->weekOfYear : '');
        $tp->setValue('FECHA_INICIO', BusinessDay::displayShort($computed['start']));
        $tp->setValue('FECHA_FIN', BusinessDay::displayShort($computed['end']->copy()->subSecond()));

        if (! $skipIng) {
            $this->fillTable($tp, 'ING_NOMBRE', 'ING_TOTAL', $computed['ingreso']);
        }
        if (! $skipEnt) {
            $this->fillTable($tp, 'ENT_NOMBRE', 'ENT_TOTAL', $computed['entrega']);
        }

        // Los gráficos pueden venir renderizados por el dashboard (PNG base64);
        // si no, se generan con GD como en el flujo clásico de informes.
        $ingPng = $this->chartPng($charts['grafico_ingreso'] ?? null) ?? $this->pie($computed['ingreso'], 'ing');
        $entPng = $this->chartPng($charts['grafico_entrega'] ?? null) ?? $this->pie($computed['entrega'], 'ent');
        // Ancho útil de la plantilla ≈ 9498 twips (≈ 633 px a 96 dpi):
        // los gráficos ocupan todo el ancho y conservan proporción.
        $tp->setImageValue('GRAFICO_INGRESO', ['path' => $ingPng, 'width' => 630, 'height' => 394, 'ratio' => true]);
        $tp->setImageValue('GRAFICO_ENTREGA', ['path' => $entPng, 'width' => 630, 'height' => 394, 'ratio' => true]);

        $trendPng = $this->chartPng($charts['grafico_tendencia'] ?? null) ?? $this->trend();
        $tp->setImageValue('GRAFICO_TENDENCIA', ['path' => $trendPng, 'width' => 630, 'height' => 301, 'ratio' => true]);

        // Gráficos del total diario (dashboard): PNG del frontend o GD en alta resolución.
        $distPng = $this->chartPng($charts['grafico_distribucion'] ?? null)
            ?? $this->pieTotals($computed['totals']['byTramite']);
        $tp->setImageValue('GRAFICO_DISTRIBUCION_TRAMITE', ['path' => $distPng, 'width' => 630, 'height' => 394, 'ratio' => true]);

        $barPng = $this->chartPng($charts['grafico_barras'] ?? null)
            ?? $this->barsTotals($computed['totals']['byTramite']);
        $tp->setImageValue('GRAFICO_TOTAL_TRAMITE', ['path' => $barPng, 'width' => 630, 'height' => 394, 'ratio' => true]);

        $trendDiaPng = $this->chartPng($charts['grafico_tendencia_dia'] ?? null)
            ?? $this->trendDia($computed['totals']['daily']);
        $tp->setImageValue('GRAFICO_TENDENCIA_DIA', ['path' => $trendDiaPng, 'width' => 630, 'height' => 301, 'ratio' => true]);

        @mkdir(dirname($outputPath), 0777, true);
        $tp->saveAs($outputPath);
        if ($cleanup) {
            @unlink($workingPath);
        }
        @unlink($ingPng);
        @unlink($entPng);
        @unlink($trendPng);
        @unlink($distPng);
        @unlink($barPng);
        @unlink($trendDiaPng);
    }

    /** true si la plantilla usa ${VAR} (sistema); false si usa {VAR} (institucional). */
    private function usesDollarMacros(string $templatePath): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($templatePath) !== true) {
            return true;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return is_string($xml) && str_contains($xml, '${');
    }

    /**
     * Variables conocidas de plantilla (texto, imágenes, tablas y filas legacy).
     * Solo estas se normalizan; otras {llaves} del texto se dejan intactas.
     */
    public const TEMPLATE_VARS = [
        'FECHA', 'NRO_CI', 'DIRIGIDO_A', 'PUESTO_DIRIGIDO_A', 'REMITENTE',
        'PUESTO_REMITENTE', 'NRO_SEMANA', 'FECHA_INICIO', 'FECHA_FIN',
        'GRAFICO_INGRESO', 'GRAFICO_ENTREGA', 'GRAFICO_TENDENCIA',
        'GRAFICO_DISTRIBUCION_TRAMITE', 'GRAFICO_TOTAL_TRAMITE', 'GRAFICO_TENDENCIA_DIA',
        'TABLA_INGRESO', 'TABLA_ENTREGA',
        'ING_NOMBRE', 'ING_TOTAL', 'ENT_NOMBRE', 'ENT_TOTAL',
    ];

    /** Partes del .docx donde pueden vivir macros. */
    public static function templateParts(\ZipArchive $zip): array
    {
        $parts = ['word/document.xml'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^word/(header\d*|footer\d*)\.xml$#', $name)) {
                $parts[] = $name;
            }
        }

        return $parts;
    }

    /**
     * Word suele partir {VAR} en varios runs ({ | VAR | }); eso impide que
     * TemplateProcessor encuentre el macro. Esta normalización los deja
     * contiguos de nuevo (solo vars conocidas, sin tocar otras {llaves}).
     */
    public static function collapseSplitMacros(string $xml): string
    {
        return (string) preg_replace_callback(
            '/\{((?:<(?!w:p[\s>]|\/w:p>)[^>]+>|[^<>{}])*?)\}/',
            function ($m) {
                $var = trim(strip_tags($m[1]));
                if (! in_array($var, self::TEMPLATE_VARS, true)) {
                    return $m[0];
                }

                return '{'.$var.'}';
            },
            $xml
        );
    }

    /**
     * Falla fuerte si hay un macro desbalanceado/desconocido (p. ej. un Enter
     * dentro de {VAR} o una llave literal): PhpWord los ignora en silencio y
     * el informe saldría sin tablas o sin gráficos.
     */
    private function assertBalancedMacros(string $xml, string $part): void
    {
        if (! preg_match_all('/\{(.*?)\}/', $xml, $m)) {
            return;
        }
        foreach ($m[1] as $inner) {
            $var = ltrim(trim(strip_tags($inner)), '$');
            if ($var === '' || in_array($var, self::TEMPLATE_VARS, true)) {
                continue;
            }
            throw new \InvalidArgumentException(
                "Macro desbalanceado o desconocido en {$part}: '".mb_substr($inner, 0, 80)."'. Revisa las llaves en la plantilla."
            );
        }
    }

    /**
     * Prepara una copia temporal de la plantilla: normaliza macros partidos
     * y, si trae {TABLA_INGRESO}/{TABLA_ENTREGA}, los reemplaza por tablas
     * w:tbl con datos. Sin marcadores equivale al flujo legacy con cloneRow.
     *
     * @return array{string, bool, bool, bool} [ruta, limpiar, skipIng, skipEnt]
     */
    private function injectMarkerTables(string $templatePath, array $computed): array
    {
        $probe = new \ZipArchive;
        if ($probe->open($templatePath) !== true) {
            return [$templatePath, false, false, false];
        }
        $probe->close();

        $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        copy($templatePath, $tmp);
        $zip = new \ZipArchive;
        $zip->open($tmp);

        $skipIng = false;
        $skipEnt = false;
        foreach (self::templateParts($zip) as $part) {
            $xml = $zip->getFromName($part);
            if (! is_string($xml)) {
                continue;
            }
            $xml = $this->collapseSplitMacros($xml);
            $this->assertBalancedMacros($xml, $part);
            $text = strip_tags($xml);
            if ($part === 'word/document.xml') {
                if (str_contains($text, '{TABLA_INGRESO}')) {
                    // Tabla de datos del dashboard (total diario: N°/trámite/total + total).
                    $rows = array_map(
                        fn ($i) => [(string) $i['nro'], $i['tramite'], (string) $i['total']],
                        $computed['totals']['byTramite']
                    );
                    if ($rows === []) {
                        $rows[] = ['—', 'Sin datos', '0'];
                    }
                    $rows[] = ['', 'TOTAL', (string) (int) ($computed['totals']['total'] ?? 0), true];
                    $xml = $this->swapMarkerForTable($xml, '{TABLA_INGRESO}', ['N°', 'TRÁMITE', 'TOTAL'], $rows);
                    $skipIng = true;
                }
                if (str_contains($text, '{TABLA_ENTREGA}')) {
                    $rows = [];
                    foreach ($computed['entrega'] as $item) {
                        $rows[] = [$item['serviceName'] ?? $item['abreviation'] ?? '—', (string) ($item['total'] ?? 0)];
                    }
                    if ($rows === []) {
                        $rows[] = ['Sin datos', '0'];
                    }
                    $xml = $this->swapMarkerForTable($xml, '{TABLA_ENTREGA}', ['SERVICIO', 'TOTAL'], $rows);
                    $skipEnt = true;
                }
            }
            $zip->deleteName($part);
            $zip->addFromString($part, $xml);
        }
        $zip->close();

        return [$tmp, true, $skipIng, $skipEnt];
    }

    /** Rango del total diario como "14/09/2026" o "14/09/2026 al 20/09/2026". */
    private function totalsRangeLabel(array $computed, bool $trend = false): string
    {
        if ($trend) {
            $daily = $computed['totals']['daily'];
            if (empty($daily)) {
                return '';
            }
            $from = Carbon::parse(reset($daily)['date'], 'UTC')->format('d/m/Y');
            $to = Carbon::parse(end($daily)['date'], 'UTC')->format('d/m/Y');

            return $from === $to ? $from : "{$from} al {$to}";
        }
        $from = $computed['start']->format('d/m/Y');
        // end es exclusivo: se muestra el día previo.
        $to = $computed['end']->copy()->subDay()->format('d/m/Y');

        return $from === $to ? $from : "{$from} al {$to}";
    }

    /**
     * Inserta título (y subtítulo opcional) centrados antes de cada gráfico
     * del total diario, con las mismas fechas del dashboard.
     */
    private function insertChartCaptions(string $workingPath, array $computed): void
    {
        $range = $this->totalsRangeLabel($computed);
        $trendRange = $this->totalsRangeLabel($computed, true);
        $total = (int) ($computed['totals']['total'] ?? 0);
        $captions = [
            'GRAFICO_DISTRIBUCION_TRAMITE' => ["Distribución por trámite · {$range}", null],
            'GRAFICO_TOTAL_TRAMITE' => ["Total por trámite · {$range}", null],
            'GRAFICO_TENDENCIA_DIA' => ["Tendencia día a día – Total · {$trendRange}", "Total: {$total}"],
        ];

        $zip = new \ZipArchive;
        if ($zip->open($workingPath) !== true) {
            return;
        }
        $xml = $zip->getFromName('word/document.xml');
        $dom = new \DOMDocument('1.0', 'UTF-8');
        if (! is_string($xml) || ! @$dom->loadXML($xml)) {
            $zip->close();

            return;
        }
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', $ns);

        $el = fn (string $name) => $dom->createElementNS($ns, $name);
        $para = function (string $text, bool $bold, int $size) use ($el, $dom, $ns) {
            $p = $el('w:p');
            $pPr = $el('w:pPr');
            $jc = $el('w:jc');
            $jc->setAttribute('w:val', 'center');
            $pPr->appendChild($jc);
            $p->appendChild($pPr);
            $r = $el('w:r');
            if ($bold || $size !== 22) {
                $rPr = $el('w:rPr');
                if ($bold) {
                    $rPr->appendChild($el('w:b'));
                }
                $sz = $el('w:sz');
                $sz->setAttribute('w:val', (string) $size);
                $rPr->appendChild($sz);
                $r->appendChild($rPr);
            }
            $t = $dom->createElementNS($ns, 'w:t');
            $t->setAttribute('xml:space', 'preserve');
            $t->appendChild($dom->createTextNode($text));
            $r->appendChild($t);
            $p->appendChild($r);

            return $p;
        };

        foreach ($captions as $marker => [$title, $subtitle]) {
            $found = $xp->query("//w:p[contains(., '{$marker}')]");
            if ($found === false || $found->length === 0) {
                continue;
            }
            /** @var \DOMElement $anchor */
            $anchor = $found->item(0);
            $anchor->parentNode->insertBefore($para($title, true, 26), $anchor);
            if ($subtitle !== null) {
                $anchor->parentNode->insertBefore($para($subtitle, false, 20), $anchor);
            }
        }

        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $dom->saveXML() ?: $xml);
        $zip->close();
    }

    /** Reemplaza el párrafo ancla por una tabla con encabezados y filas dadas. */
    private function swapMarkerForTable(string $xml, string $marker, array $headers, array $rows): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        if (! @$dom->loadXML($xml)) {
            return $xml;
        }
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', $ns);
        $found = $xp->query("//w:p[contains(., '{$marker}')]");
        if ($found === false || $found->length === 0) {
            return $xml;
        }

        foreach ($found as $p) {
            $p->parentNode->replaceChild($this->buildDataTable($dom, $ns, $headers, $rows), $p);
        }

        return $dom->saveXML() ?: $xml;
    }

    /** Anchos de columna que suman el ancho útil (9498 twips). */
    private function columnWidths(int $cols): array
    {
        if ($cols <= 2) {
            return [6200, 3298];
        }

        return array_merge([1100], array_fill(0, $cols - 2, (int) round(7098 / max(1, $cols - 2))), [1300]);
    }

    /** Tabla w:tbl compacta a todo el ancho útil con encabezado en negrita. */
    private function buildDataTable(\DOMDocument $dom, string $ns, array $headers, array $rows): \DOMElement
    {
        $el = fn (string $name) => $dom->createElementNS($ns, $name);
        $tx = function (string $name, string $text) use ($dom, $ns) {
            $n = $dom->createElementNS($ns, $name);
            $n->appendChild($dom->createTextNode($text));

            return $n;
        };

        $tbl = $el('w:tbl');
        $pr = $el('w:tblPr');
        $borders = $el('w:tblBorders');
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $edge) {
            $b = $el('w:'.$edge);
            $b->setAttribute('w:val', 'single');
            $b->setAttribute('w:sz', '6');
            $b->setAttribute('w:color', '000000');
            $borders->appendChild($b);
        }
        $pr->appendChild($borders);
        // Márgenes de celda mínimos para filas compactas.
        $cellMar = $el('w:tblCellMar');
        foreach (['top' => '0', 'left' => '56', 'bottom' => '0', 'right' => '56'] as $edge => $w) {
            $m = $el('w:'.$edge);
            $m->setAttribute('w:w', $w);
            $m->setAttribute('w:type', 'dxa');
            $cellMar->appendChild($m);
        }
        $pr->appendChild($cellMar);
        $tbl->appendChild($pr);
        // Ancho útil 9498 twips repartido: primera y última angostas si hay 3+ columnas.
        $widths = $this->columnWidths(count($headers));
        $grid = $el('w:tblGrid');
        foreach ($widths as $w) {
            $c = $el('w:gridCol');
            $c->setAttribute('w:w', (string) $w);
            $grid->appendChild($c);
        }
        $tbl->appendChild($grid);

        // Filas [celdas..., bold?] (bold solo si trae 4° elemento true).
        $all = array_merge([array_merge($headers, [true])], array_map(function ($r) use ($headers) {
            $cells = array_values($r);
            $bold = count($cells) > count($headers) ? (bool) array_pop($cells) : false;

            return array_merge($cells, [$bold]);
        }, $rows));
        foreach ($all as $row) {
            $bold = (bool) array_pop($row);
            $tr = $el('w:tr');
            foreach (array_values($row) as $cell) {
                $tc = $el('w:tc');
                $p = $el('w:p');
                $pPr = $el('w:pPr');
                $spacing = $el('w:spacing');
                $spacing->setAttribute('w:after', '0');
                $spacing->setAttribute('w:line', '240');
                $spacing->setAttribute('w:lineRule', 'auto');
                $pPr->appendChild($spacing);
                $p->appendChild($pPr);
                $r = $el('w:r');
                $rPr = $el('w:rPr');
                if ($bold) {
                    $rPr->appendChild($el('w:b'));
                }
                $sz = $el('w:sz');
                $sz->setAttribute('w:val', '18');
                $rPr->appendChild($sz);
                $szCs = $el('w:szCs');
                $szCs->setAttribute('w:val', '18');
                $rPr->appendChild($szCs);
                $r->appendChild($rPr);
                $r->appendChild($tx('w:t', $cell));
                $p->appendChild($r);
                $tc->appendChild($p);
                $tr->appendChild($tc);
            }
            $tbl->appendChild($tr);
        }

        return $tbl;
    }

    public function generate(User $generator, array $data): Report
    {
        $mode = $data['mode'] ?? 'WEEK';
        $computed = $this->compute($mode, $data['date'] ?? null, $data['weekStart'] ?? null, $data['from'] ?? null, $data['to'] ?? null);
        $template = $this->templates->resolveOrCreate($data['templateId'] ?? null, $generator);

        $name = $this->fileName($mode, $computed['start'], $computed['end'], $data['nroCI']);
        $path = Storage::path(self::DIR.'/'.$name);

        $this->renderTo($template->filePath, $computed, [
            'now' => now('UTC'),
            'nroCI' => $data['nroCI'],
            'dirigidoA' => $data['dirigidoA'],
            'puestoDirigidoA' => $data['puestoDirigidoA'],
        ], $generator->fresh(['activePosition']), $path, $data['charts'] ?? []);

        return Report::create([
            'nroCI' => $data['nroCI'],
            'dirigidoA' => $data['dirigidoA'],
            'puestoDirigidoA' => $data['puestoDirigidoA'],
            'templateId' => $template->id,
            'mode' => $mode,
            'periodStart' => $computed['start'],
            'periodEnd' => $computed['end'],
            'fileName' => $name,
            'filePath' => $path,
            'totalIngreso' => $computed['totalIngreso'],
            'totalEntrega' => $computed['totalEntrega'],
            'generatedById' => $generator->id,
        ])->fresh(['template', 'generatedBy']);
    }

    /** Vista previa: mismo cálculo, .docx adjunto sin guardar fila. */
    public function preview(User $generator, array $data): string
    {
        $mode = $data['mode'] ?? 'WEEK';
        $computed = $this->compute($mode, $data['date'] ?? null, $data['weekStart'] ?? null, $data['from'] ?? null, $data['to'] ?? null);
        $template = $this->templates->resolveOrCreate($data['templateId'] ?? null, $generator);

        $path = Storage::path(self::DIR.'/tmp/preview_'.uniqid().'.docx');
        $this->renderTo($template->filePath, $computed, [
            'now' => now('UTC'),
            'nroCI' => $data['nroCI'],
            'dirigidoA' => $data['dirigidoA'],
            'puestoDirigidoA' => $data['puestoDirigidoA'],
        ], $generator->fresh(['activePosition']), $path, $data['charts'] ?? []);

        return $path;
    }

    private function fillTable(TemplateProcessor $tp, string $nameVar, string $totalVar, array $items): void
    {
        // Plantillas nuevas (ej. solo TABLA_INGRESO de totales) pueden no traer
        // esta tabla legacy: se omite sin romper el informe.
        try {
            $tp->cloneRow($nameVar, max(1, count($items)));
        } catch (\PhpOffice\PhpWord\Exception\Exception) {
            return;
        }
        if (empty($items)) {
            $tp->setValue("{$nameVar}#1", 'Sin datos');
            $tp->setValue("{$totalVar}#1", '0');

            return;
        }
        foreach ($items as $i => $item) {
            $n = $i + 1;
            $tp->setValue("{$nameVar}#{$n}", $item['serviceName'] ?? $item['abreviation'] ?? '—');
            $tp->setValue("{$totalVar}#{$n}", (string) ($item['total'] ?? 0));
        }
    }

    /**
     * Decodifica un PNG base64 (opcionalmente data-URL) a un archivo temporal.
     * Devuelve null si no se envió gráfico; 422 si el contenido no es una imagen.
     */
    private function chartPng(?string $base64): ?string
    {
        if (! is_string($base64) || trim($base64) === '') {
            return null;
        }
        if (preg_match('#^data:image/\w+;base64,#i', $base64)) {
            $base64 = substr($base64, (int) strpos($base64, ',') + 1);
        }
        $binary = base64_decode($base64, true);
        if ($binary === false || @getimagesizefromstring($binary) === false) {
            throw new HttpResponseException(
                ApiResponse::error('El gráfico adjunto no es una imagen válida.', 422)
            );
        }
        $path = tempnam(sys_get_temp_dir(), 'chart').'.png';
        file_put_contents($path, $binary);

        return $path;
    }

    private function pie(array $items, string $prefix, int $width = 760, int $height = 420): string
    {
        $path = sys_get_temp_dir()."/{$prefix}_".uniqid().'.png';

        // Etiquetas y leyenda igual que el dashboard: labels con código y
        // leyenda "código – nombre" con porcentaje entre paréntesis.
        $labels = array_map(
            fn ($i) => $i['codigo'] ?: ($i['abreviation'] ?? ($i['serviceName'] ?? '?')),
            $items
        );
        $legendLabels = array_map(function ($i) {
            $parts = array_filter([$i['codigo'] ?? '', $i['abreviation'] ?: ($i['serviceName'] ?? '')], fn ($v) => $v !== '');

            return implode(' – ', $parts);
        }, $items);

        $values = array_map(fn ($i) => (int) ($i['total'] ?? 0), $items);
        if (empty($values) || array_sum($values) === 0) {
            $labels = ['Sin datos'];
            $legendLabels = ['Sin datos'];
            $values = [1];
        }

        return GdPieChart::render(['labels' => $labels, 'legendLabels' => $legendLabels, 'values' => $values], $path, $width, $height);
    }

    /** Torta del total diario en alta resolución (etiqueta N° + leyenda con abreviación). */
    private function pieTotals(array $byTramite): string
    {
        $mapped = array_map(fn ($i) => [
            'codigo' => 'N° '.$i['nro'],
            'abreviation' => $i['abreviation'] ?? $i['tramite'],
            'serviceName' => $i['tramite'],
            'total' => $i['total'],
        ], $byTramite);

        return $this->pie($mapped, 'dist', 1100, 650);
    }

    /** Barras del total diario en alta resolución. */
    private function barsTotals(array $byTramite): string
    {
        $path = sys_get_temp_dir().'/bars_'.uniqid().'.png';
        $labels = [];
        $values = [];
        foreach ($byTramite as $i) {
            $labels[] = $i['nro'].'. '.($i['abreviation'] ?: $i['tramite']);
            $values[] = (int) $i['total'];
        }
        if ($values === []) {
            $labels = ['Sin datos'];
            $values = [0];
        }

        return GdBarChart::render(['labels' => $labels, 'values' => $values], $path, 1200);
    }

    /** Tendencia día a día del total (una serie) en alta resolución. */
    private function trendDia(array $daily): string
    {
        $points = array_map(
            fn ($r) => ['day' => substr($r['date'], 8, 2), 'total' => $r['total']],
            $daily
        );
        $path = sys_get_temp_dir().'/trenddia_'.uniqid().'.png';

        return GdTrendChart::render($points, $path, 1200, 570, [['total', 'Total', [147, 51, 234]]]);
    }

    /** PNG con la tendencia diaria del mes actual (Ingreso/Entrega), como el dashboard. */
    private function trend(): string
    {
        $today = now('UTC');
        $from = $today->copy()->startOfMonth()->toDateString();
        $to = $today->copy()->toDateString();
        $rows = $this->dash->daily($from, $to);

        $points = array_map(
            fn ($r) => ['day' => substr($r['date'], 8, 2), 'ingreso' => $r['ingreso'], 'entrega' => $r['entrega']],
            $rows
        );

        $path = sys_get_temp_dir().'/trend_'.uniqid().'.png';

        return GdTrendChart::render($points, $path);
    }
}
