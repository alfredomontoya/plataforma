<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EntregaItemSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ServicesSeeder;
use Database\Seeders\TemplatesSeeder;
use Database\Seeders\TipoRegistroSeeder;
use Database\Seeders\TramitesSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Console\Command;

/**
 * Seed orquestado con flags (equiv. db:seed* del stack anterior).
 */
class SeedPlatform extends Command
{
    protected $signature = 'platform:seed
        {--skip-roles : omite roles y permisos}
        {--skip-users : omite usuarios}
        {--skip-services : omite servicios}
        {--skip-templates : omite plantillas}
        {--skip-entries : omite trámites demo}
        {--skip-totals : (obsoleto) los totales ahora salen de tramite_registros}
        {--no-wipe : no vacía antes de sembrar}
        {--month=2026-08 : mes de trámites demo YYYY-MM (se ignora con --from)}
        {--from= : inicio de rango YYYY-MM-DD}
        {--to= : fin de rango YYYY-MM-DD (default hoy)}
        {--min=3 : trámites mín. por día}
        {--max=12 : trámites máx. por día}
        {--seed= : semilla PRNG (defecto 20261104)}
        {--force : confirma el borrado masivo (obligatorio en producción)}';

    protected $description = 'Siembra la plataforma (wipe → roles → users → services → templates → trámites demo)';

    public function handle(): int
    {
        $production = app()->environment('production');

        // En producción solo funciona con --force explícito (evita borrados accidentales).
        if ($production && ! $this->option('force')) {
            $this->error('platform:seed está bloqueado en producción. Use --force si REALMENTE desea reiniciar los datos.');

            return self::FAILURE;
        }

        if (! $this->option('no-wipe') && $this->input->isInteractive()
            && ! $this->option('force')
            && ! $this->confirm('Se borrarán TODOS los datos de la plataforma. ¿Continuar?', false)) {
            $this->info('Cancelado.');

            return self::SUCCESS;
        }

        if (! $this->option('no-wipe')) {
            DatabaseSeeder::wipe($production);
            $this->info('Wipe OK.');
        }

        if (! $this->option('skip-roles')) {
            (new RolesAndPermissionsSeeder())->run();
        }
        if (! $this->option('skip-users')) {
            (new UsersSeeder())->run();
        }
        (new TipoRegistroSeeder())->run();
        (new EntregaItemSeeder())->run();
        if (! $this->option('skip-services')) {
            (new ServicesSeeder())->run();
        }
        if (! $this->option('skip-templates')) {
            (new TemplatesSeeder())->run();
        }
        if (! $this->option('skip-entries')) {
            (new TramitesSeeder(
                $this->option('month') ?: null, $this->option('from') ?: null, $this->option('to') ?: null,
                (int) $this->option('min'), (int) $this->option('max'),
                $this->option('seed') !== null ? (int) $this->option('seed') : null,
            ))->run();
        }

        $this->info('Seed OK.');
        return self::SUCCESS;
    }
}
