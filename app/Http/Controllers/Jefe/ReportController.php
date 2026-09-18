<?php

namespace App\Http\Controllers\Jefe;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateReportRequest;
use App\Models\Report;
use App\Services\ReportService;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

/**
 * Informes (contrato 04 § Informes, solo ADMIN/JEFE).
 */
class ReportController extends Controller
{
    public function preview(GenerateReportRequest $request, ReportService $reports)
    {
        $path = $reports->preview($request->user(), $request->validated());

        // ?format=pdf → vista exacta (membrete, pies, tablas) vía LibreOffice.
        if ($request->query('format') === 'pdf') {
            $pdf = $this->convertToPdf($path);
            @unlink($path);
            if (! $pdf) {
                return ApiResponse::error('Vista PDF no disponible en este servidor (falta LibreOffice).', 501);
            }

            return response()->download($pdf, 'vista-previa.pdf', ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
        }

        return response()->download($path, 'vista-previa.docx')->deleteFileAfterSend(true);
    }

    public function generate(GenerateReportRequest $request, ReportService $reports): JsonResponse
    {
        return ApiResponse::created($reports->generate($request->user(), $request->validated()));
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 10), 1), 100);
        $q = Report::with(['template', 'generatedBy']);

        if ($search = $request->query('search')) {
            $q->where(fn ($w) => $w->where('nroCI', 'like', "%{$search}%")
                ->orWhere('dirigidoA', 'like', "%{$search}%")
                ->orWhere('fileName', 'like', "%{$search}%"));
        }
        // Filtros por día calendario Bolivia (UTC-4) sobre createdAt
        foreach (['from', 'to', 'date'] as $f) {
            if (! $request->query($f)) {
                continue;
            }
            [$s, $e] = BusinessDay::laPazDayBounds($request->query($f));
            if ($f === 'from') {
                $q->where('created_at', '>=', $s);
            } elseif ($f === 'to') {
                $q->where('created_at', '<', $e);
            } else {
                $q->where('created_at', '>=', $s)->where('created_at', '<', $e);
            }
        }

        return ApiResponse::paginated($q->orderBy('created_at', 'desc')->paginate($limit));
    }

    public function download(string $id)
    {
        $report = Report::findOrFail($id);
        if (! file_exists($report->filePath)) {
            return ApiResponse::error('Archivo no encontrado.', 404);
        }

        return response()->download($report->filePath, $report->fileName);
    }

    /** Vista exacta del informe guardado (PDF con membrete y pies). 501 si no hay conversor. */
    public function pdf(string $id)
    {
        $report = Report::findOrFail($id);
        if (! file_exists($report->filePath)) {
            return ApiResponse::error('Archivo no encontrado.', 404);
        }
        $cached = (string) preg_replace('/\.docx$/i', '.pdf', $report->filePath);
        if (! file_exists($cached) || filemtime($cached) < filemtime($report->filePath)) {
            $tmp = $this->convertToPdf($report->filePath);
            if (! $tmp) {
                return ApiResponse::error('Vista PDF no disponible en este servidor (falta LibreOffice).', 501);
            }
            @copy($tmp, $cached);
            @unlink($tmp);
        }

        return response()->file($cached, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($cached).'"',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $report = Report::findOrFail($id);
        $path = $report->filePath;
        $report->delete();
        if ($path && file_exists($path)) {
            @unlink($path);
            @unlink((string) preg_replace('/\.docx$/i', '.pdf', $path));
        }

        return ApiResponse::ok(['message' => 'Informe eliminado.']);
    }

    /** Binario de LibreOffice (env LIBREOFFICE_PATH o rutas comunes). Null si no existe. */
    private function sofficeBinary(): ?string
    {
        $configured = (string) env('LIBREOFFICE_PATH', '');
        $candidates = array_filter([
            $configured ?: null,
            'C:\Program Files\LibreOffice\program\soffice.exe',
            'C:\Program Files (x86)\LibreOffice\program\soffice.exe',
            '/usr/bin/soffice',
            '/usr/bin/libreoffice',
            '/opt/libreoffice/program/soffice',
        ]);
        foreach ($candidates as $bin) {
            if (is_file($bin) && is_executable($bin)) {
                return $bin;
            }
        }
        $lookup = DIRECTORY_SEPARATOR === '\\' ? 'where soffice 2>NUL' : 'command -v soffice 2>/dev/null';
        $found = trim((string) shell_exec($lookup));
        $first = strtok($found, "\r\n");
        if (is_string($first) && $first !== '' && is_file($first)) {
            return $first;
        }

        return null;
    }

    /** Convierte un .docx a PDF temporal con LibreOffice headless. Null si falla. */
    private function convertToPdf(string $docxPath): ?string
    {
        $bin = $this->sofficeBinary();
        if (! $bin || ! file_exists($docxPath)) {
            return null;
        }
        $tmpDir = sys_get_temp_dir().'/pdfprev_'.uniqid();
        @mkdir($tmpDir, 0777, true);
        $result = Process::timeout(60)->run([$bin, '--headless', '--convert-to', 'pdf', '--outdir', $tmpDir, $docxPath]);
        $pdf = $tmpDir.'/'.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';
        if (! $result->successful() || ! file_exists($pdf)) {
            return null;
        }
        $final = sys_get_temp_dir().'/pdfprev_'.uniqid().'.pdf';
        @rename($pdf, $final);
        @rmdir($tmpDir);

        return file_exists($final) ? $final : null;
    }
}
