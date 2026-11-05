<?php

namespace App\Services;

use App\Models\User;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Exceptions\HttpResponseException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exportaciones del registro de trámites (PDF/Excel).
 * Respeta el alcance por rol: operadores solo sus registros, ADMIN todos.
 */
class TramiteReportService
{
    private const HEADERS = [
        'Fecha',
        'Hora',
        'Tipo de trámite',
        'Nro. placa / póliza',
        'Observaciones',
        'Usuario',
        'Estado',
    ];

    public function __construct(private TramiteRegistroService $tramites) {}

    public function pdf(User $user, string $from, string $to, ?string $search = null): Response
    {
        $records = $this->tramites->forRange($user, $from, $to, $search);
        $rows = $this->rows($records);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->loadHtml(view('reports.tramites-pdf', [
            'headers' => self::HEADERS,
            'rows' => $rows,
            'usuario' => $user->displayName,
            'desde' => $this->dateLabel($from),
            'hasta' => $this->dateLabel($to),
            'search' => $search,
            'total' => count($rows),
            'activos' => count(array_filter($records, fn ($r) => (bool) ($r['activo'] ?? false))),
            'generadoEl' => Carbon::now(BusinessDay::TZ_DISPLAY)->format('d/m/Y H:i'),
        ])->render(), 'UTF-8');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->fileName('pdf').'"',
        ]);
    }

    public function excel(User $user, string $from, string $to, ?string $search = null): BinaryFileResponse
    {
        $records = $this->tramites->forRange($user, $from, $to, $search);
        $rows = $this->rows($records);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Registros');
        $sheet->setShowGridLines(false);

        $lastColumn = 'G';
        $lastRow = 8 + count($rows);
        $headerRow = 8;

        // Encabezado del reporte.
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', 'REPORTE DE REGISTRO DE TRÁMITES');
        $sheet->getRowDimension(1)->setRowHeight(26);

        $this->excelMeta($sheet, 2, 'Usuario:', $user->displayName);
        $this->excelMeta($sheet, 3, 'Periodo:', "De {$this->dateLabel($from)} a {$this->dateLabel($to)}");
        $this->excelMeta($sheet, 4, 'Total de registros:', (string) count($rows));
        $this->excelMeta($sheet, 5, 'Búsqueda:', $search ?: '—');
        $this->excelMeta($sheet, 6, 'Generado:', Carbon::now(BusinessDay::TZ_DISPLAY)->format('d/m/Y H:i'));

        // Cabecera de columnas.
        foreach (self::HEADERS as $index => $header) {
            $sheet->setCellValueExplicit(
                $this->columnLetter($index + 1).$headerRow,
                $header,
                DataType::TYPE_STRING,
            );
        }

        // Filas de datos (como texto para conservar ceros iniciales y evitar fórmulas).
        foreach ($rows as $r => $row) {
            $rowNumber = $headerRow + 1 + $r;
            foreach (array_values($row) as $c => $value) {
                $sheet->setCellValueExplicit(
                    $this->columnLetter($c + 1).$rowNumber,
                    (string) $value,
                    DataType::TYPE_STRING,
                );
            }
        }

        // Estilos.
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F71F3D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle('A2:A6')->getFont()->setBold(true);

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '44403C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        if ($rows !== []) {
            $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D6D3D1']],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            ]);
            $sheet->getStyle('A'.($headerRow + 1).":B{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D'.($headerRow + 1).":D{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('G'.($headerRow + 1).":G{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")->getAlignment()->setWrapText(true);

            $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");
        }

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")->getFont()->setSize(10);
        $sheet->getRowDimension($headerRow)->setRowHeight(22);
        $sheet->freezePane('A'.($headerRow + 1));

        $widths = ['A' => 11, 'B' => 8, 'C' => 27, 'D' => 17, 'E' => 33, 'F' => 22, 'G' => 10];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        // Impresión en carta vertical: todo el ancho en una página y encabezados repetidos.
        $sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_LETTER)
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(1, $headerRow)
            ->setPrintArea("A1:{$lastColumn}{$lastRow}")
            ->setHorizontalCentered(true);

        $sheet->getPageMargins()
            ->setTop(0.5)
            ->setRight(0.4)
            ->setBottom(0.5)
            ->setLeft(0.4)
            ->setHeader(0.2)
            ->setFooter(0.2);

        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getFont()->setName('Calibri');
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        $path = tempnam(sys_get_temp_dir(), 'tramites_');
        if ($path === false) {
            throw new HttpResponseException(
                response()->json(['error' => 'No se pudo generar el archivo Excel.'], 500)
            );
        }
        $file = $path.'.xlsx';
        (new Xlsx($spreadsheet))->save($file);
        $spreadsheet->disconnectWorksheets();
        @unlink($path);

        return response()->download(
            $file,
            $this->fileName('xlsx'),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    private function excelMeta($sheet, int $row, string $label, string $value): void
    {
        $sheet->setCellValueExplicit("A{$row}", $label, DataType::TYPE_STRING);
        $sheet->mergeCells("B{$row}:G{$row}");
        $sheet->setCellValueExplicit("B{$row}", $value, DataType::TYPE_STRING);
    }

    /** @return array<int, array<int, string>> */
    private function rows(array $records): array
    {
        return array_map(function (array $row): array {
            return [
                $this->dateLabel(substr((string) ($row['date'] ?? ''), 0, 10)),
                $this->timeLabel($row['created_at'] ?? null),
                (string) ($row['service']['name'] ?? '—'),
                (string) ($row['placa'] ?? '—'),
                (string) ($row['observaciones'] ?? ''),
                $this->userName($row['user'] ?? []),
                ! empty($row['activo']) ? 'Activo' : 'Inactivo',
            ];
        }, $records);
    }

    private function userName(array $user): string
    {
        $name = trim(implode(' ', array_filter([
            $user['firstName'] ?? null,
            $user['lastName'] ?? null,
            $user['paternalSurname'] ?? null,
            $user['maternalSurname'] ?? null,
        ])));

        return $name !== '' ? $name : (string) ($user['username'] ?? '—');
    }

    private function dateLabel(?string $date): string
    {
        $date = trim((string) $date);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '—';
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $date)->format('d/m/Y');
        } catch (\Throwable) {
            return '—';
        }
    }

    private function timeLabel(?string $createdAt): string
    {
        if (! $createdAt) {
            return '—';
        }

        try {
            return Carbon::parse($createdAt)->tz(BusinessDay::TZ_DISPLAY)->format('H:i');
        } catch (\Throwable) {
            return '—';
        }
    }

    private function columnLetter(int $index): string
    {
        return chr(64 + $index);
    }

    private function fileName(string $extension): string
    {
        return 'reporte_registro_tramites_'.now(BusinessDay::TZ_DISPLAY)->format('Ymd_His').'.'.$extension;
    }
}
