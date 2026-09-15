<?php

namespace App\Console\Commands;

use App\Models\Entry;
use Carbon\Carbon;
use Database\Seeders\EntriesSeeder;
use Illuminate\Console\Command;

/**
 * Siembra solo la tabla entries (la vacía antes solo con --clear).
 *
 * Modo A (sin fechas): 1 día (hoy UTC), n filas por usuario.
 *   php artisan platform:entries-seed
 *
 * Modo B (rango): n filas por día para cada usuario.
 *   php artisan platform:entries-seed --from=2026-08-01 --to=2026-08-31
 *
 * n se sortea por usuario y día en [perUserMin, perUserMax], topado al
 * nº de servicios elegibles (ENTREGA solo tiene 3). Con --legacy se usa
 * el modo original (1 fila por día×servicio con round-robin).
 *
 * Modo C --total (convive con los anteriores): genera exactamente n registros
 * repartidos de forma equitativa por día entre --fechaini y --fechafin
 * (defaults: 1er día del mes actual → hoy UTC).
 *   php artisan platform:entries-seed --total=500 --fechaini=2025-06-01
 *
 * Por defecto no queda fuera ningún usuario elegible (OPERATOR_*, ADMIN y JEFE).
 * --operators-only restringe a solo OPERATOR_* y --excludeJefe saca al JEFE.
 *   php artisan platform:entries-seed --from=2026-09-01 --to=2026-09-15 --perUserMin=10 --perUserMax=10 --clear --force
 */
class SeedEntries extends Command
{
    protected $signature = 'platform:entries-seed
        {--month= : mes YYYY-MM (se ignora con --from; sin --from ni --month = hoy)}
        {--from= : inicio de rango YYYY-MM-DD}
        {--to= : fin de rango YYYY-MM-DD inclusivo (default hoy cuando hay --from)}
        {--perUserMin=5 : filas mín. por usuario/día}
        {--perUserMax=10 : filas máx. por usuario/día}
        {--legacy : modo original día×servicio en vez de per-user}
        {--total=0 : modo total: n registros exactos repartidos equitativamente por día}
        {--fechaini= : fecha inicial YYYY-MM-DD del modo total (default 1er día del mes actual)}
        {--fechafin= : fecha final YYYY-MM-DD inclusiva del modo total (default hoy)}
        {--min=3 : cantidad mín. día hábil}
        {--max=10 : cantidad máx. día hábil}
        {--weekendMin=1 : cantidad mín. fin de semana}
        {--weekendMax=3 : cantidad máx. fin de semana}
        {--operators-only : solo OPERATOR_* (por defecto entran todos)}
        {--excludeJefe : excluye al JEFE (por defecto incluido; solo demo: la API real lo rechaza)}
        {--seed= : semilla PRNG (defecto año*100+mes)}
        {--clear : vacía entries antes de sembrar}
        {--force : sin confirmación}';

    protected $description = 'Genera registros en entries (la vacía antes solo con --clear)';

    public function handle(): int
    {
        $legacy = (bool) $this->option('legacy');
        $total = (int) $this->option('total');
        if ($total > 0 && $legacy) {
            $this->error('Las opciones --total y --legacy son excluyentes.');
            return self::FAILURE;
        }

        // Validar el modo total ANTES de limpiar (no perder datos por un error).
        $fechaini = null;
        $fechafin = null;
        if ($total > 0) {
            $fechaini = $this->option('fechaini') ?: Carbon::now('UTC')->startOfMonth()->toDateString();
            $fechafin = $this->option('fechafin') ?: Carbon::now('UTC')->toDateString();
            try {
                $start = Carbon::createFromFormat('Y-m-d', $fechaini, 'UTC')->startOfDay();
                $end = Carbon::createFromFormat('Y-m-d', $fechafin, 'UTC')->startOfDay();
            } catch (\Throwable) {
                $this->error('fechaini/fechafin deben tener formato YYYY-MM-DD.');
                return self::FAILURE;
            }
            if ($start->gt($end)) {
                $this->error('fechaini no puede ser posterior a fechafin.');
                return self::FAILURE;
            }
        }

        $perUserMin = ($legacy || $total > 0) ? null : (int) $this->option('perUserMin');
        $perUserMax = ($legacy || $total > 0) ? null : (int) $this->option('perUserMax');
        if ($perUserMin !== null && $perUserMin > $perUserMax) {
            $this->error('perUserMin no puede ser mayor que perUserMax.');
            return self::FAILURE;
        }

        $clear = (bool) $this->option('clear');
        if ($clear && ! $this->option('force') && $this->input->isInteractive()) {
            $count = Entry::count();
            if (! $this->confirm("Se eliminarán {$count} filas de entries. ¿Continuar?", true)) {
                $this->info('Cancelado.');
                return self::SUCCESS;
            }
        }

        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : null;

        (new EntriesSeeder(
            $this->option('month') ?: null,
            $this->option('from') ?: null,
            $this->option('to') ?: null,
            (int) $this->option('min'),
            (int) $this->option('max'),
            (int) $this->option('weekendMin'),
            (int) $this->option('weekendMax'),
            $perUserMin,
            $perUserMax,
            ! $this->option('operators-only'),
            ! $this->option('excludeJefe'),
            $seed,
            $clear,
            $total > 0 ? $total : null,
            $fechaini,
            $fechafin,
        ))->run();

        if ($clear) {
            $this->info('Entries limpiadas.');
        }
        $this->info('Entries generadas: ' . Entry::count() . '.');

        return self::SUCCESS;
    }
}
