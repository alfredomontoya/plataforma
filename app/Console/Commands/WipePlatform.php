<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

/** Vacía las 6 tablas + archivos huérfanos (equiv. db:wipe). */
class WipePlatform extends Command
{
    protected $signature = 'platform:wipe';
    protected $description = 'Vacía usuarios, servicios, plantillas, entradas y archivos';

    public function handle(): int
    {
        DatabaseSeeder::wipe();
        $this->info('Wipe OK.');
        return self::SUCCESS;
    }
}
