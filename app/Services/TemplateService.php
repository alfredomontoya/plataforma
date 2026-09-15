<?php

namespace App\Services;

use App\Models\Template;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;

/**
 * Plantillas .docx (docs 03 § Plantillas): primera = default, default exclusiva,
 * borrado fila+archivo tolerante, fallback explícita → default → auto-creación.
 */
class TemplateService
{
    public const DIR = 'templates';

    public function store(User $user, string $name, \Illuminate\Http\UploadedFile $file): Template
    {
        $isFirst = Template::count() === 0;

        $template = Template::create([
            'name' => $name,
            'fileName' => $file->getClientOriginalName(),
            'filePath' => '', // se completa abajo
            'uploadedById' => $user->id,
            'isDefault' => $isFirst,
        ]);

        $stored = $file->storeAs(self::DIR, $template->id . '_' . $file->getClientOriginalName());
        $template->update(['filePath' => Storage::path($stored)]);

        return $template->fresh();
    }

    public function setDefault(Template $template): Template
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($template) {
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

        $path = Storage::path(self::DIR . '/default.docx');
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

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addTitle('Informe de trámites', 1);
        foreach (['FECHA', 'NRO_CI', 'DIRIGIDO_A', 'PUESTO_DIRIGIDO_A', 'REMITENTE',
                     'PUESTO_REMITENTE', 'NRO_SEMANA', 'FECHA_INICIO', 'FECHA_FIN'] as $ph) {
            $section->addText($ph . ': ${' . $ph . '}');
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

        (new \PhpOffice\PhpWord\Writer\Word2007($phpWord))->save($path);
    }
}
