<?php

namespace App\Console\Commands;

use App\Models\Entry;
use App\Models\Service;
use Database\Seeders\TotalsSeeder;
use Illuminate\Console\Command;

/**
 * Genera totales diarios demo (delega en TotalsSeeder).
 * Persiste como entries con type=INGRESO (módulo unificado).
 *   php artisan platform:totals-seed --force
 *   php artisan platform:totals-seed --from=2026-09-01 --to=2026-09-18 --force
 */
class SeedTotals extends Command
{
    protected $signature = 'platform:totals-seed
        {--from= : inicio YYYY-MM-DD (default 1er día del mes actual)}
        {--to= : fin YYYY-MM-DD inclusivo (default hoy)}
        {--clear : vacía las entries de tipo INGRESO antes}
        {--force : sin confirmación}';

    protected $description = 'Genera totales diarios demo (entries type=INGRESO)';

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

        $clearCount = fn (): int => Entry::where('type', Service::TYPE_INGRESO)->count();

        if ($this->option('clear') && ! $this->option('force') && $this->input->isInteractive()) {
            if (! $this->confirm('Se eliminarán '.$clearCount().' entries de tipo INGRESO. ¿Continuar?', true)) {
                $this->info('Cancelado.');

                return self::SUCCESS;
            }
        }
        if ($this->option('clear')) {
            Entry::where('type', Service::TYPE_INGRESO)->delete();
        }

        (new TotalsSeeder(null, $this->option('from') ?: null, $this->option('to') ?: null))->run();

        $this->info('Totales demo generados: '.$clearCount().' entries INGRESO.');

        return self::SUCCESS;
    }
}
