<?php

namespace App\Console\Commands;

use App\Models\TramiteRegistro;
use Database\Seeders\TramitesSeeder;
use Illuminate\Console\Command;

/**
 * Genera trámites demo (delega en TramitesSeeder).
 * La fuente de conteos es `tramite_registros`; `entries` es histórico.
 *   php artisan platform:totals-seed --force
 *   php artisan platform:totals-seed --from=2026-09-01 --to=2026-09-18 --force
 */
class SeedTotals extends Command
{
    protected $signature = 'platform:totals-seed
        {--from= : inicio YYYY-MM-DD (default 1er día del mes actual)}
        {--to= : fin YYYY-MM-DD inclusivo (default hoy)}
        {--clear : vacía tramite_registros antes}
        {--force : sin confirmación}';

    protected $description = 'Genera trámites demo en tramite_registros';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('platform:totals-seed está bloqueado en producción (genera datos demo). Use --force para confirmar.');

            return self::FAILURE;
        }

        if ($this->option('from') && $this->option('to') && $this->option('from') > $this->option('to')) {
            $this->error('from no puede ser posterior a to.');

            return self::FAILURE;
        }

        if ($this->option('clear') && ! $this->option('force') && $this->input->isInteractive()) {
            if (! $this->confirm('Se eliminarán '.TramiteRegistro::query()->count().' trámites registrados. ¿Continuar?', true)) {
                $this->info('Cancelado.');

                return self::SUCCESS;
            }
        }
        if ($this->option('clear')) {
            TramiteRegistro::query()->delete();
        }

        (new TramitesSeeder(null, $this->option('from') ?: null, $this->option('to') ?: null))->run();

        $this->info('Trámites demo generados: '.TramiteRegistro::query()->count().' registros.');

        return self::SUCCESS;
    }
}
