<?php

namespace App\Http\Controllers\Jefe;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Services\TemplateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plantillas (contrato 04 § Plantillas, solo ADMIN/JEFE).
 */
class TemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $q = Template::with('uploadedBy');
        if ($search = $request->query('search')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('fileName', 'like', "%{$search}%"));
        }

        return ApiResponse::paginated($q->orderBy('created_at', 'desc')->paginate($limit));
    }

    public function default(): JsonResponse
    {
        return ApiResponse::ok(Template::where('isDefault', true)->firstOrFail());
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::ok(Template::with('uploadedBy')->findOrFail($id));
    }

    public function store(Request $request, TemplateService $templates): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'file' => ['required', 'file', 'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'max:10240'],
        ], [
            'file.mimetypes' => 'Solo se permiten archivos .docx.',
        ]);

        return ApiResponse::created(
            $templates->store($request->user(), $request->input('name'), $request->file('file'))
        );
    }

    public function setDefault(string $id, TemplateService $templates): JsonResponse
    {
        return ApiResponse::ok($templates->setDefault(Template::findOrFail($id)));
    }

    public function destroy(string $id, TemplateService $templates): JsonResponse
    {
        $templates->destroy(Template::findOrFail($id));

        return ApiResponse::ok(['message' => 'Plantilla eliminada.']);
    }
}
