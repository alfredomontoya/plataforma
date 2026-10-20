<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    /**
     * db:seed: wipe (con guarda de entorno) → roles → usuarios → servicios → plantillas.
     */
    public function run(): void
    {
        self::wipe();

        $this->call([
            RolesAndPermissionsSeeder::class,
            UsersSeeder::class,
            ServicesSeeder::class,
            TemplatesSeeder::class,
            // EntriesSeeder::class,
        ]);
    }

    /**
     * Borrado masivo de tablas y archivos (destructivo).
     *
     * En producción NO hace nada salvo $force (requiere --force explícito en el
     * comando); en local/test pide confirmación interactiva.
     *
     * @param  bool  $force  permite el wipe en producción
     */
    public static function wipe(bool $force = false): void
    {
        if (! $force && ! self::destructiveAllowed('wipe')) {
            return;
        }

        self::wipeData();
    }

    /**
     * Vacía las tablas y archivos sin comprobar el entorno (uso interno).
     */
    public static function wipeData(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['reports', 'daily_totals', 'entries', 'templates', 'positions', 'services',
                     'model_has_roles', 'model_has_permissions', 'users'] as $table) {
            DB::table($table)->delete();
        }
        Schema::enableForeignKeyConstraints();

        foreach (['reports', 'templates'] as $dir) {
            foreach ((array) Storage::files($dir) as $file) {
                Storage::delete($file);
            }
        }
    }

    /** ¿Permitido borrar datos? Producción exige --force; local/test confirman. */
    public static function destructiveAllowed(): bool
    {
        return ! app()->environment('production');
    }
}
