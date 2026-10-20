<?php

namespace App\Http\Controllers\Jefe;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Services\TemplateInspector;
use App\Services\TemplateService;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
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
            'file' => ['required', 'file', 'mimes:docx,zip',
                'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip',
                'max:10240'],
        ], [
            'file.mimes' => 'Solo se permiten archivos .docx.',
            'file.mimetypes' => 'Solo se permiten archivos .docx.',
        ]);

        $file = $request->file('file');
        // El .docx es un ZIP: se valida estructura y tamaño descomprimido real.
        TemplateService::assertValidDocx($file->getRealPath());

        return ApiResponse::created(
            $templates->store($request->user(), $request->input('name'), $file)
        );
    }

    /** True si el usuario puede mutar la plantilla (ADMIN o dueño). */
    private function canMutate(Request $request, Template $template): bool
    {
        return $request->user()->role === 'ADMIN'
            || $template->uploadedById === $request->user()->id;
    }

    /** Aborta 403 con el envelope de error si el usuario no puede mutar (A4). */
    private function assertCanMutate(Request $request, Template $template): void
    {
        if (! $this->canMutate($request, $template)) {
            throw new HttpResponseException(
                ApiResponse::error('Sin permiso para modificar esta plantilla.', 403)
            );
        }
    }

    public function setDefault(Request $request, string $id, TemplateService $templates): JsonResponse
    {
        $template = Template::findOrFail($id);
        $this->assertCanMutate($request, $template);

        return ApiResponse::ok($templates->setDefault($template));
    }

    public function destroy(Request $request, string $id, TemplateService $templates): JsonResponse
    {
        $template = Template::findOrFail($id);
        $this->assertCanMutate($request, $template);
        $templates->destroy($template);

        return ApiResponse::ok(['message' => 'Plantilla eliminada.']);
    }

    public function download(string $id)
    {
        $template = Template::findOrFail($id);
        if (! file_exists($template->filePath)) {
            return ApiResponse::error('Archivo no encontrado.', 404);
        }

        return response()->download(
            $template->filePath,
            basename(TemplateService::sanitizeFileName($template->fileName))
        );
    }

    public function inspect(string $id, TemplateInspector $inspector): JsonResponse
    {
        $template = Template::findOrFail($id);
        TemplateService::assertValidDocx($template->filePath);

        return ApiResponse::ok($inspector->inspect($template->filePath));
    }

    public function paragraphs(string $id, TemplateInspector $inspector): JsonResponse
    {
        $template = Template::findOrFail($id);
        TemplateService::assertValidDocx($template->filePath);

        return ApiResponse::ok($inspector->paragraphs($template->filePath));
    }

    public function updateParagraphs(Request $request, string $id, TemplateInspector $inspector): JsonResponse
    {
        $data = $request->validate([
            'texts' => ['required', 'array', 'min:1', 'max:500'],
            'texts.*' => ['required', 'string', 'max:2000'],
        ]);
        $template = Template::findOrFail($id);
        $this->assertCanMutate($request, $template);
        if (! file_exists($template->filePath)) {
            return ApiResponse::error('Archivo no encontrado.', 404);
        }
        TemplateService::assertValidDocx($template->filePath);
        $inspector->updateParagraphs($template->filePath, $data['texts']);

        return ApiResponse::ok(['message' => 'Plantilla actualizada.']);
    }

    public function appendParagraph(Request $request, string $id, TemplateInspector $inspector): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);
        $template = Template::findOrFail($id);
        $this->assertCanMutate($request, $template);
        if (! file_exists($template->filePath)) {
            return ApiResponse::error('Archivo no encontrado.', 404);
        }
        TemplateService::assertValidDocx($template->filePath);

        return ApiResponse::created($inspector->appendParagraph($template->filePath, $data['text']));
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        $template = Template::findOrFail($id);
        $this->assertCanMutate($request, $template);
        if (! file_exists($template->filePath)) {
            return ApiResponse::error('Archivo no encontrado.', 404);
        }
        $fileName = basename(TemplateService::sanitizeFileName($template->fileName));
        $copy = Template::create([
            'name' => $template->name.' (copia)',
            'fileName' => $fileName,
            'filePath' => '',
            'uploadedById' => $request->user()->id,
            'isDefault' => false,
        ]);
        // Destino siempre dentro del directorio de la original, con nombre saneado.
        $dest = dirname($template->filePath).'/'.$copy->id.'_'.$fileName;
        copy($template->filePath, $dest);
        $copy->update(['filePath' => $dest]);

        return ApiResponse::created($copy->fresh());
    }
}
