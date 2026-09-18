<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EntriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ServicesSeeder;
use Database\Seeders\TemplatesSeeder;
use Database\Seeders\TotalsSeeder;
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
        {--skip-entries : omite entradas}
        {--skip-totals : omite totales diarios}
        {--no-wipe : no vacía antes de sembrar}
        {--month=2026-08 : mes de entradas YYYY-MM (se ignora con --from)}
        {--from= : inicio de rango YYYY-MM-DD}
        {--to= : fin de rango YYYY-MM-DD (default hoy)}
        {--min=3 : cantidad mín. día hábil}
        {--max=10 : cantidad máx. día hábil}
        {--weekendMin=1 : cantidad mín. fin de semana}
        {--weekendMax=3 : cantidad máx. fin de semana}
        {--perUserMin= : filas mín. por usuario/día (modo per-user; omite = legacy día×servicio)}
        {--perUserMax= : filas máx. por usuario/día (modo per-user)}
        {--operators-only : solo OPERATOR_* en modo per-user (por defecto entran todos)}
        {--excludeJefe : excluye al JEFE en modo per-user (por defecto incluido; solo demo)}
        {--seed= : semilla PRNG (defecto año*100+mes)}';

    protected $description = 'Siembra la plataforma (wipe → roles → users → services → templates → entries → totals)';

    public function handle(): int
    {
        if (! $this->option('no-wipe')) {
            DatabaseSeeder::wipe();
            $this->info('Wipe OK.');
        }

        if (! $this->option('skip-roles')) {
            (new RolesAndPermissionsSeeder())->run();
        }
        if (! $this->option('skip-users')) {
            (new UsersSeeder())->run();
        }
        if (! $this->option('skip-services')) {
            (new ServicesSeeder())->run();
        }
        if (! $this->option('skip-templates')) {
            (new TemplatesSeeder())->run();
        }
        if (! $this->option('skip-entries')) {
            $perUserMin = $this->option('perUserMin') !== null ? (int) $this->option('perUserMin') : null;
            $perUserMax = $this->option('perUserMax') !== null ? (int) $this->option('perUserMax') : null;
            if ($perUserMin !== null && $perUserMax === null) {
                $perUserMax = $perUserMin;
            }
            (new EntriesSeeder(
                $this->option('month') ?: null, $this->option('from') ?: null, $this->option('to') ?: null,
                (int) $this->option('min'), (int) $this->option('max'),
                (int) $this->option('weekendMin'), (int) $this->option('weekendMax'),
                $perUserMin, $perUserMax,
                ! (bool) $this->option('operators-only'),
                ! (bool) $this->option('excludeJefe'),
                $this->option('seed') !== null ? (int) $this->option('seed') : null,
            ))->run();
        }
        if (! $this->option('skip-totals')) {
            (new TotalsSeeder(
                $this->option('month') ?: null, $this->option('from') ?: null, $this->option('to') ?: null,
            ))->run();
        }

        $this->info('Seed OK.');
        return self::SUCCESS;
    }
}
