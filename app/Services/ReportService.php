<?php

namespace App\Services;

use App\Models\Report;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\GdPieChart;
use Carbon\Carbon;
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
    ) {}

    /** Periodo + agregados según modo. */
    public function compute(string $mode, ?string $date, ?string $weekStart): array
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
        ];
    }

    public function fileName(string $mode, Carbon $start, Carbon $end, string $nro): string
    {
        $kind = $mode === 'DAY' ? 'diario' : 'semanal';
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $nro);
        return "informe_{$kind}_{$start->format('Y-m-d')}_a_{$end->format('Y-m-d')}_{$safe}_" . time() . '.docx';
    }

    public function renderTo(string $templatePath, array $computed, array $meta, User $generator, string $outputPath): void
    {
        // Las plantillas institucionales usan {VAR} y párrafos {TABLA_*} como
        // ancla de tablas (se inyectan como w:tbl real en una copia temporal).
        // La plantilla simple del sistema usa ${VAR} + filas clonables.
        [$workingPath, $cleanup, $skipIng, $skipEnt] = $this->injectMarkerTables($templatePath, $computed);
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

        $ingPng = $this->pie($computed['ingreso'], 'ing');
        $entPng = $this->pie($computed['entrega'], 'ent');
        $tp->setImageValue('GRAFICO_INGRESO', ['path' => $ingPng, 'width' => 480, 'height' => 300, 'ratio' => false]);
        $tp->setImageValue('GRAFICO_ENTREGA', ['path' => $entPng, 'width' => 480, 'height' => 300, 'ratio' => false]);

        @mkdir(dirname($outputPath), 0777, true);
        $tp->saveAs($outputPath);
        if ($cleanup) {
            @unlink($workingPath);
        }
        @unlink($ingPng);
        @unlink($entPng);
    }

    /** true si la plantilla usa ${VAR} (sistema); false si usa {VAR} (institucional). */
    private function usesDollarMacros(string $templatePath): bool
    {
        $zip = new \ZipArchive();
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
    private const TEMPLATE_VARS = [
        'FECHA', 'NRO_CI', 'DIRIGIDO_A', 'PUESTO_DIRIGIDO_A', 'REMITENTE',
        'PUESTO_REMITENTE', 'NRO_SEMANA', 'FECHA_INICIO', 'FECHA_FIN',
        'GRAFICO_INGRESO', 'GRAFICO_ENTREGA', 'TABLA_INGRESO', 'TABLA_ENTREGA',
        'ING_NOMBRE', 'ING_TOTAL', 'ENT_NOMBRE', 'ENT_TOTAL',
    ];

    /** Partes del .docx donde pueden vivir macros. */    private function templateParts(\ZipArchive $zip): array
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
    private function collapseSplitMacros(string $xml): string
    {
        return (string) preg_replace_callback(
            '/\{((?:<(?!w:p[\s>]|\/w:p>)[^>]+>|[^<>{}])*?)\}/',
            function ($m) {
                $var = trim(strip_tags($m[1]));
                if (! in_array($var, self::TEMPLATE_VARS, true)) {
                    return $m[0];
                }
                return '{' . $var . '}';
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
                "Macro desbalanceado o desconocido en {$part}: '" . mb_substr($inner, 0, 80) . "'. Revisa las llaves en la plantilla."
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
        $probe = new \ZipArchive();
        if ($probe->open($templatePath) !== true) {
            return [$templatePath, false, false, false];
        }
        $probe->close();

        $tmp = tempnam(sys_get_temp_dir(), 'tpl') . '.docx';
        copy($templatePath, $tmp);
        $zip = new \ZipArchive();
        $zip->open($tmp);

        $skipIng = false;
        $skipEnt = false;
        foreach ($this->templateParts($zip) as $part) {
            $xml = $zip->getFromName($part);
            if (! is_string($xml)) {
                continue;
            }
            $xml = $this->collapseSplitMacros($xml);
            $this->assertBalancedMacros($xml, $part);
            $text = strip_tags($xml);
            if ($part === 'word/document.xml') {
                if (str_contains($text, '{TABLA_INGRESO}')) {
                    $xml = $this->swapMarkerForTable($xml, '{TABLA_INGRESO}', $computed['ingreso']);
                    $skipIng = true;
                }
                if (str_contains($text, '{TABLA_ENTREGA}')) {
                    $xml = $this->swapMarkerForTable($xml, '{TABLA_ENTREGA}', $computed['entrega']);
                    $skipEnt = true;
                }
            }
            $zip->deleteName($part);
            $zip->addFromString($part, $xml);
        }
        $zip->close();

        return [$tmp, true, $skipIng, $skipEnt];
    }

    /** Reemplaza el párrafo ancla por una tabla Servicio/Total con los datos. */
    private function swapMarkerForTable(string $xml, string $marker, array $items): string
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [$item['serviceName'] ?? $item['abreviation'] ?? '—', (string) ($item['total'] ?? 0)];
        }
        if ($rows === []) {
            $rows[] = ['Sin datos', '0'];
        }

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
            $p->parentNode->replaceChild($this->buildDataTable($dom, $ns, $rows), $p);
        }

        return $dom->saveXML() ?: $xml;
    }

    /** Tabla w:tbl de 2 columnas con encabezado en negrita. */
    private function buildDataTable(\DOMDocument $dom, string $ns, array $rows): \DOMElement
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
            $b = $el('w:' . $edge);
            $b->setAttribute('w:val', 'single');
            $b->setAttribute('w:sz', '6');
            $b->setAttribute('w:color', '000000');
            $borders->appendChild($b);
        }
        $pr->appendChild($borders);
        $tbl->appendChild($pr);
        $grid = $el('w:tblGrid');
        foreach ([6000, 3000] as $w) {
            $c = $el('w:gridCol');
            $c->setAttribute('w:w', (string) $w);
            $grid->appendChild($c);
        }
        $tbl->appendChild($grid);

        $all = array_merge([['SERVICIO', 'TOTAL', true]], array_map(fn ($r) => [$r[0], $r[1], false], $rows));
        foreach ($all as [$name, $total, $bold]) {
            $tr = $el('w:tr');
            foreach ([$name, $total] as $cell) {
                $tc = $el('w:tc');
                $p = $el('w:p');
                $r = $el('w:r');
                if ($bold) {
                    $rPr = $el('w:rPr');
                    $rPr->appendChild($el('w:b'));
                    $r->appendChild($rPr);
                }
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
        $computed = $this->compute($mode, $data['date'] ?? null, $data['weekStart'] ?? null);
        $template = $this->templates->resolveOrCreate($data['templateId'] ?? null, $generator);

        $name = $this->fileName($mode, $computed['start'], $computed['end'], $data['nroCI']);
        $path = Storage::path(self::DIR . '/' . $name);

        $this->renderTo($template->filePath, $computed, [
            'now' => now('UTC'),
            'nroCI' => $data['nroCI'],
            'dirigidoA' => $data['dirigidoA'],
            'puestoDirigidoA' => $data['puestoDirigidoA'],
        ], $generator->fresh(['activePosition']), $path);

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
        $computed = $this->compute($mode, $data['date'] ?? null, $data['weekStart'] ?? null);
        $template = $this->templates->resolveOrCreate($data['templateId'] ?? null, $generator);

        $path = Storage::path(self::DIR . '/tmp/preview_' . uniqid() . '.docx');
        $this->renderTo($template->filePath, $computed, [
            'now' => now('UTC'),
            'nroCI' => $data['nroCI'],
            'dirigidoA' => $data['dirigidoA'],
            'puestoDirigidoA' => $data['puestoDirigidoA'],
        ], $generator->fresh(['activePosition']), $path);

        return $path;
    }

    private function fillTable(TemplateProcessor $tp, string $nameVar, string $totalVar, array $items): void
    {
        if (empty($items)) {
            $tp->cloneRow($nameVar, 1);
            $tp->setValue("{$nameVar}#1", 'Sin datos');
            $tp->setValue("{$totalVar}#1", '0');
            return;
        }
        $tp->cloneRow($nameVar, count($items));
        foreach ($items as $i => $item) {
            $n = $i + 1;
            $tp->setValue("{$nameVar}#{$n}", $item['serviceName'] ?? $item['abreviation'] ?? '—');
            $tp->setValue("{$totalVar}#{$n}", (string) ($item['total'] ?? 0));
        }
    }

    private function pie(array $items, string $prefix): string
    {
        $path = sys_get_temp_dir() . "/{$prefix}_" . uniqid() . '.png';
        $labels = array_map(fn ($i) => $i['abreviation'] ?: substr($i['serviceName'] ?? '?', 0, 12), $items);
        $values = array_map(fn ($i) => (int) ($i['total'] ?? 0), $items);
        if (empty($values) || array_sum($values) === 0) {
            $labels = ['Sin datos'];
            $values = [1];
        }

        return GdPieChart::render(['labels' => $labels, 'values' => $values], $path);
    }
}
