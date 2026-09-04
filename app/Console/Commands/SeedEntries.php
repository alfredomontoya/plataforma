<?php

namespace App\Console\Commands;

use App\Models\Entry;
use Database\Seeders\EntriesSeeder;
use Illuminate\Console\Command;

/**
 * Siembra solo la tabla entries, limpiándola antes (salvo --no-clean).
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
        {--min=3 : cantidad mín. día hábil}
        {--max=10 : cantidad máx. día hábil}
        {--weekendMin=1 : cantidad mín. fin de semana}
        {--weekendMax=3 : cantidad máx. fin de semana}
        {--operators-only : solo OPERATOR_* (por defecto incluye ADMIN)}
        {--includeJefe : incluye JEFE (solo demo: la API real lo rechaza)}
        {--seed= : semilla PRNG (defecto año*100+mes)}
        {--no-clean : no vacía entries antes de sembrar}
        {--force : sin confirmación}';

    protected $description = 'Limpia entries y genera n registros por usuario (por día si se dan fechas)';

    public function handle(): int
    {
        $legacy = (bool) $this->option('legacy');
        $perUserMin = $legacy ? null : (int) $this->option('perUserMin');
        $perUserMax = $legacy ? null : (int) $this->option('perUserMax');
        if (! $legacy && $perUserMin > $perUserMax) {
            $this->error('perUserMin no puede ser mayor que perUserMax.');
            return self::FAILURE;
        }

        $clean = ! $this->option('no-clean');
        if ($clean && ! $this->option('force') && $this->input->isInteractive()) {
            $count = Entry::count();
            if (! $this->confirm("Se eliminarán {$count} filas de entries. ¿Continuar?", true)) {
                $this->info('Cancelado.');
                return self::SUCCESS;
            }
        }

        if ($clean) {
            Entry::query()->delete();
            $this->info('Entries limpiadas.');
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
            (bool) $this->option('includeJefe'),
            $seed,
            false, // ya limpiamos aquí (o --no-clean)
        ))->run();

        $this->info('Entries generadas: ' . Entry::count() . '.');

        return self::SUCCESS;
    }
}
