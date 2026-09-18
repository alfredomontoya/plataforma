<?php

namespace App\Console\Commands;

use App\Models\DailyTotal;
use Database\Seeders\TotalsSeeder;
use Illuminate\Console\Command;

/**
 * Genera totales diarios demo (delega en TotalsSeeder).
 *   php artisan platform:totals-seed --force
 *   php artisan platform:totals-seed --from=2026-09-01 --to=2026-09-18 --force
 */
class SeedTotals extends Command
{
    protected $signature = 'platform:totals-seed
        {--from= : inicio YYYY-MM-DD (default 1er día del mes actual)}
        {--to= : fin YYYY-MM-DD inclusivo (default hoy)}
        {--clear : vacía daily_totals antes}
        {--force : sin confirmación}';

    protected $description = 'Genera totales diarios demo en daily_totals';

    public function handle(): int
    {
        if ($this->option('from') && $this->option('to') && $this->option('from') > $this->option('to')) {
            $this->error('from no puede ser posterior a to.');

            return self::FAILURE;
        }

        if ($this->option('clear') && ! $this->option('force') && $this->input->isInteractive()) {
            if (! $this->confirm('Se eliminarán '.DailyTotal::count().' filas de daily_totals. ¿Continuar?', true)) {
                $this->info('Cancelado.');

                return self::SUCCESS;
            }
        }
        if ($this->option('clear')) {
            DailyTotal::query()->delete();
        }

        (new TotalsSeeder(null, $this->option('from') ?: null, $this->option('to') ?: null))->run();

        $this->info('Totales demo generados: '.DailyTotal::count().' filas.');

        return self::SUCCESS;
    }
}
