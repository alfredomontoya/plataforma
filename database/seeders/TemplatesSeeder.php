<?php

namespace Database\Seeders;

use App\Models\Template;
use App\Models\User;
use App\Services\TemplateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Copia sistema → default.docx + fila default (omite si ya hay).
 */
class TemplatesSeeder extends Seeder
{
    public function run(): void
    {
        if (Template::count() > 0) {
            return;
        }

        $assets = base_path('assets/templates/plantilla-sistema.docx');

        Storage::makeDirectory(TemplateService::DIR);
        $dest = Storage::path(TemplateService::DIR . '/default.docx');
        if (file_exists($assets)) {
            copy($assets, $dest);
        } else {
            // Último recurso: base simple directo al destino (nunca pisa la fuente).
            app(TemplateService::class)->buildBaseFile($dest);
        }

        $admin = User::where('username', 'admin')->first() ?? User::firstOrFail();

        Template::create([
            'name' => 'Plantilla del Sistema',
            'fileName' => 'default.docx',
            'filePath' => $dest,
            'uploadedById' => $admin->id,
            'isDefault' => true,
        ]);
    }
}
