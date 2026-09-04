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

/**
 * Informes (contrato 04 § Informes, solo ADMIN/JEFE).
 */
class ReportController extends Controller
{
    public function preview(GenerateReportRequest $request, ReportService $reports)
    {
        $path = $reports->preview($request->user(), $request->validated());

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

    public function destroy(string $id): JsonResponse
    {
        $report = Report::findOrFail($id);
        $path = $report->filePath;
        $report->delete();
        if ($path && file_exists($path)) {
            @unlink($path);
        }

        return ApiResponse::ok(['message' => 'Informe eliminado.']);
    }
}
