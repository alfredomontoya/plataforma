<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BackupService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Copias de seguridad de la base de datos — solo ADMIN.
 */
class BackupController extends Controller
{
    /** Configuración + lista de copias con auditoría. */
    public function index(BackupService $backups): JsonResponse
    {
        $list = $backups->list();

        return ApiResponse::ok([
            'directory' => $backups->directory(),
            'max' => $backups->maxBackups(),
            'limits' => ['min' => BackupService::MIN_BACKUPS, 'max' => BackupService::MAX_BACKUPS],
            'backups' => $list['backups'],
            'uploadRestores' => $list['uploadRestores'],
        ]);
    }

    /** Crea una copia nueva (aplica retención y registra al usuario). */
    public function store(Request $request, BackupService $backups): JsonResponse
    {
        try {
            return ApiResponse::created($backups->create($request->user()));
        } catch (InvalidArgumentException|ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            Log::error('Backup create failed', ['exception' => $e->getMessage()]);

            return ApiResponse::error('No se pudo crear la copia: '.$e->getMessage(), 500);
        }
    }

    /** Guarda directorio + número máximo de copias en la configuración. */
    public function updateConfig(Request $request, BackupService $backups): JsonResponse
    {
        $data = $request->validate([
            'directory' => 'required|string|max:500',
            'max' => 'required|integer|min:'.BackupService::MIN_BACKUPS.'|max:'.BackupService::MAX_BACKUPS,
        ]);

        try {
            return ApiResponse::ok($backups->configure($data['directory'], (int) $data['max']));
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /** Subcarpetas del servidor para explorar el destino de las copias. */
    public function directories(Request $request, BackupService $backups): JsonResponse
    {
        $data = $request->validate(['path' => 'sometimes|nullable|string|max:2000']);

        try {
            return ApiResponse::ok($backups->directories($data['path'] ?? null));
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }
    }

    /** Crea una carpeta dentro de la ruta explorada y devuelve su ruta. */
    public function createDirectory(Request $request, BackupService $backups): JsonResponse
    {
        $data = $request->validate([
            'path' => 'required|string|max:2000',
            'name' => 'required|string|max:100',
        ]);

        try {
            return ApiResponse::created($backups->createDirectory($data['path'], $data['name']));
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }
    }

    /** Descarga una copia (.sql) y registra la descarga. */
    public function download(Request $request, BackupService $backups): BinaryFileResponse|JsonResponse
    {
        $data = $request->validate(['file' => 'required|string']);

        try {
            $path = $backups->download($data['file'], $request->user());

            return response()->download($path, $data['file'], [
                'Content-Type' => 'application/sql',
            ]);
        } catch (InvalidArgumentException) {
            return ApiResponse::error('Nombre de copia inválido.', 400);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }
    }

    /** Restaura una copia existente del servidor (los datos actuales se reemplazan). */
    public function restore(Request $request, BackupService $backups): JsonResponse
    {
        $data = $request->validate(['file' => 'required|string']);

        try {
            $backups->restore($data['file'], $request->user());

            return ApiResponse::ok(['message' => 'Base de datos restaurada.']);
        } catch (InvalidArgumentException) {
            return ApiResponse::error('Nombre de copia inválido.', 400);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            Log::error('Backup restore failed', ['exception' => $e->getMessage()]);

            return ApiResponse::error('No se pudo restaurar: '.$e->getMessage(), 500);
        }
    }

    /** Restaura un .sql subido por el usuario (archivo descargado previamente). */
    public function restoreUpload(Request $request, BackupService $backups): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|max:102400', // 100 MB
        ]);

        try {
            $backups->restoreUpload($data['file'], $request->user());

            return ApiResponse::ok(['message' => 'Base de datos restaurada desde el archivo subido.']);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            Log::error('Backup upload restore failed', ['exception' => $e->getMessage()]);

            return ApiResponse::error('No se pudo restaurar: '.$e->getMessage(), 500);
        }
    }
}
