<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

/** Vacía las 7 tablas + archivos huérfanos (equiv. db:wipe). */
class WipePlatform extends Command
{
    protected $signature = 'platform:wipe {--force : permite el wipe en producción}';
    protected $description = 'Vacía usuarios, servicios, plantillas, entradas, totales y archivos';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('platform:wipe está bloqueado en producción. Use --force si REALMENTE desea borrar los datos.');

            return self::FAILURE;
        }

        if ($this->input->isInteractive() && ! $this->option('force')
            && ! $this->confirm('Se borrarán TODOS los datos de la plataforma. ¿Continuar?', false)) {
            $this->info('Cancelado.');

            return self::SUCCESS;
        }

        DatabaseSeeder::wipe(true);
        $this->info('Wipe OK.');

        return self::SUCCESS;
    }
}
