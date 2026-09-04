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
        $tp = new TemplateProcessor($templatePath);
        $tp->setValue('FECHA', BusinessDay::displayLong($meta['now']));
        $tp->setValue('NRO_CI', $meta['nroCI']);
        $tp->setValue('DIRIGIDO_A', $meta['dirigidoA']);
        $tp->setValue('PUESTO_DIRIGIDO_A', $meta['puestoDirigidoA']);
        $tp->setValue('REMITENTE', trim("{$generator->title} {$generator->fullName}"));
        $tp->setValue('PUESTO_REMITENTE', $generator->activePosition?->position ?? '');
        $tp->setValue('NRO_SEMANA', $computed['mode'] === 'WEEK' ? (string) $computed['start']->weekOfYear : '');
        $tp->setValue('FECHA_INICIO', BusinessDay::displayShort($computed['start']));
        $tp->setValue('FECHA_FIN', BusinessDay::displayShort($computed['end']->copy()->subSecond()));

        $this->fillTable($tp, 'ING_NOMBRE', 'ING_TOTAL', $computed['ingreso']);
        $this->fillTable($tp, 'ENT_NOMBRE', 'ENT_TOTAL', $computed['entrega']);

        $ingPng = $this->pie($computed['ingreso'], 'ing');
        $entPng = $this->pie($computed['entrega'], 'ent');
        $tp->setImageValue('GRAFICO_INGRESO', ['path' => $ingPng, 'width' => 480, 'height' => 300, 'ratio' => false]);
        $tp->setImageValue('GRAFICO_ENTREGA', ['path' => $entPng, 'width' => 480, 'height' => 300, 'ratio' => false]);

        @mkdir(dirname($outputPath), 0777, true);
        $tp->saveAs($outputPath);
        @unlink($ingPng);
        @unlink($entPng);
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
