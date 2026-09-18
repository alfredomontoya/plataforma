<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

/** Vacía las 7 tablas + archivos huérfanos (equiv. db:wipe). */
class WipePlatform extends Command
{
    protected $signature = 'platform:wipe';
    protected $description = 'Vacía usuarios, servicios, plantillas, entradas, totales y archivos';

    public function handle(): int
    {
        DatabaseSeeder::wipe();
        $this->info('Wipe OK.');
        return self::SUCCESS;
    }
}
