<?php

namespace App\Services;

use App\Models\Template;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Writer\Word2007;

/**
 * Plantillas .docx (docs 03 § Plantillas): primera = default, default exclusiva,
 * borrado fila+archivo tolerante, fallback explícita → default → auto-creación.
 */
class TemplateService
{
    public const DIR = 'templates';

    /** Máximo de entradas permitidas dentro del .docx (anti zip bomb). */
    public const MAX_ZIP_FILES = 2000;

    /** Máximo de bytes descomprimidos en total dentro del .docx (100 MB). */
    public const MAX_ZIP_UNCOMPRESSED = 104857600;

    /**
     * Sanea un nombre de archivo para uso seguro en disco/BD: elimina rutas
     * (basename), reemplaza caracteres peligrosos y limita a 191 caracteres.
     * Garantiza extensión .docx.
     */
    public static function sanitizeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        // basename() puede devolver '.' o '..' para entradas como '../' o '.'.
        if ($name === '.' || $name === '..') {
            $name = '';
        }
        $name = (string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/u', '_', $name);
        $name = trim($name);
        if ($name === '' || $name === '.docx') {
            return 'plantilla.docx';
        }
        // Separar extensión para respetar el tope de 191 sobre el nombre final.
        if (! str_ends_with(strtolower($name), '.docx')) {
            $name .= '.docx';
        }
        if (mb_strlen($name) > 191) {
            $name = mb_substr($name, 0, 191 - 5).'.docx';
        }

        return $name;
    }

    /**
     * Valida que el archivo sea un .docx real: ZIP con word/document.xml,
     * número de entradas y tamaño descomprimido acotados (anti zip bomb).
     * Lanza 422 si no cumple.
     */
    public static function assertValidDocx(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new HttpResponseException(
                ApiResponse::error('No se pudo abrir la plantilla.', 422)
            );
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new HttpResponseException(
                ApiResponse::error('El archivo no es un .docx válido.', 422)
            );
        }
        $invalid = $zip->numFiles > self::MAX_ZIP_FILES
            || $zip->locateName('word/document.xml') === false;
        $uncompressed = 0;
        if (! $invalid) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $uncompressed += (int) ($stat['size'] ?? 0);
            }
        }
        $zip->close();

        if ($invalid || $uncompressed > self::MAX_ZIP_UNCOMPRESSED) {
            throw new HttpResponseException(
                ApiResponse::error('El archivo no es un .docx válido o excede el tamaño permitido.', 422)
            );
        }
    }

    public function store(User $user, string $name, UploadedFile $file): Template
    {
        $isFirst = Template::count() === 0;
        // El nombre original del cliente nunca define la ruta en disco (A5).
        $fileName = self::sanitizeFileName((string) $file->getClientOriginalName());

        $template = Template::create([
            'name' => $name,
            'fileName' => $fileName,
            'filePath' => '', // se completa abajo
            'uploadedById' => $user->id,
            'isDefault' => $isFirst,
        ]);

        // Nombre físico impredecible: UUID evita colisiones y cualquier rastro
        // del nombre original del cliente (path traversal).
        $stored = $file->storeAs(self::DIR, $template->id.'_'.Str::uuid().'.docx');
        $template->update(['filePath' => Storage::path($stored)]);

        return $template->fresh();
    }

    public function setDefault(Template $template): Template
    {
        DB::transaction(function () use ($template) {
            Template::where('isDefault', true)->update(['isDefault' => false]);
            $template->update(['isDefault' => true]);
        });

        return $template->fresh();
    }

    public function destroy(Template $template): void
    {
        $path = $template->filePath;
        $template->delete();
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    public function resolve(?string $templateId): ?Template
    {
        if ($templateId && ($t = Template::find($templateId))) {
            return $t;
        }

        return Template::where('isDefault', true)->first();
    }

    /**
     * Cadena de fallback: explícita → default → auto-creación desde la base versionada.
     * El informe siempre guarda un templateId válido.
     */
    public function resolveOrCreate(?string $templateId, User $user): Template
    {
        if ($template = $this->resolve($templateId)) {
            if (! file_exists($template->filePath)) {
                $this->restoreBaseFile($template->filePath);
            }

            return $template;
        }

        $path = Storage::path(self::DIR.'/default.docx');
        $this->restoreBaseFile($path);

        return Template::create([
            'name' => 'Plantilla del Sistema',
            'fileName' => 'default.docx',
            'filePath' => $path,
            'uploadedById' => $user->id,
            'isDefault' => true,
        ]);
    }

    /**
     * Restaura el archivo base: primero la fuente versionada con formato;
     * solo como último recurso genera la versión simple (nunca pisa la fuente).
     */
    public function restoreBaseFile(string $path): void
    {
        $assets = base_path('assets/templates/plantilla-sistema.docx');
        if (file_exists($assets)) {
            @mkdir(dirname($path), 0777, true);
            copy($assets, $path);

            return;
        }
        $this->buildBaseFile($path);
    }

    /** Genera el .docx base con ${PLACEHOLDERS} y filas clonables. */
    public function buildBaseFile(string $path): void
    {
        @mkdir(dirname($path), 0777, true);

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addTitle('Informe de trámites', 1);
        foreach (['FECHA', 'NRO_CI', 'DIRIGIDO_A', 'PUESTO_DIRIGIDO_A', 'REMITENTE',
            'PUESTO_REMITENTE', 'NRO_SEMANA', 'FECHA_INICIO', 'FECHA_FIN'] as $ph) {
            $section->addText($ph.': ${'.$ph.'}');
        }

        $section->addTitle('Trámites ingresados', 2);
        $table = $section->addTable(['borderSize' => 6]);
        $table->addRow()->addCell()->addText('Servicio / Total');
        $table->addRow();
        $table->addCell()->addText('${ING_NOMBRE}');
        $table->addCell()->addText('${ING_TOTAL}');

        $section->addTitle('Trámites entregados', 2);
        $table2 = $section->addTable(['borderSize' => 6]);
        $table2->addRow()->addCell()->addText('Servicio / Total');
        $table2->addRow();
        $table2->addCell()->addText('${ENT_NOMBRE}');
        $table2->addCell()->addText('${ENT_TOTAL}');

        $section->addTitle('Gráficos', 2);
        $section->addText('${GRAFICO_INGRESO}');
        $section->addText('${GRAFICO_ENTREGA}');

        $section->addTitle('Tendencia diaria del mes', 2);
        $section->addText('${GRAFICO_TENDENCIA}');

        (new Word2007($phpWord))->save($path);
    }
}
