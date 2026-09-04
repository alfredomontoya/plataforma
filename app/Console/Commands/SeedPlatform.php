<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EntriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ServicesSeeder;
use Database\Seeders\TemplatesSeeder;
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
        {--no-wipe : no vacía antes de sembrar}
        {--operators=10 : operadores aleatorios extra (mitad/mitad)}
        {--userSeed=42 : semilla de usuarios}
        {--month=2026-08 : mes de entradas YYYY-MM (se ignora con --from)}
        {--from= : inicio de rango YYYY-MM-DD}
        {--to= : fin de rango YYYY-MM-DD (default hoy)}
        {--min=3 : cantidad mín. día hábil}
        {--max=10 : cantidad máx. día hábil}
        {--weekendMin=1 : cantidad mín. fin de semana}
        {--weekendMax=3 : cantidad máx. fin de semana}
        {--perUserMin= : filas mín. por usuario/día (modo per-user; omite = legacy día×servicio)}
        {--perUserMax= : filas máx. por usuario/día (modo per-user)}
        {--allUsers : incluye ADMIN en modo per-user}
        {--includeJefe : incluye JEFE en modo per-user (solo demo)}
        {--seed= : semilla PRNG (defecto año*100+mes)}';

    protected $description = 'Siembra la plataforma (wipe → roles → users → services → templates → entries)';

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
            (new UsersSeeder((int) $this->option('operators'), (int) $this->option('userSeed')))->run();
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
                (bool) $this->option('allUsers'), (bool) $this->option('includeJefe'),
                $this->option('seed') !== null ? (int) $this->option('seed') : null,
            ))->run();
        }

        $this->info('Seed OK.');
        return self::SUCCESS;
    }
}
