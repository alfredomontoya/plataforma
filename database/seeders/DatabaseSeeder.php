<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    /**
     * db:seed vacía todo primero (7 tablas + huérfanos en storage).
     * Orden: wipe → roles → usuarios → servicios → plantillas → entradas.
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

    public static function wipe(): void
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
}
