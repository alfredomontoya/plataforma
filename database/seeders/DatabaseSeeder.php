<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    /**
     * db:seed: wipe (con guarda de entorno) → roles → RRHH → usuarios →
     * servicios → plantillas.
     */
    public function run(): void
    {
        self::wipe();

        $this->call([
            RolesAndPermissionsSeeder::class,
            AreaSeeder::class,
            PuestoPvehSeeder::class,
            TratamientoSeeder::class,
            EmpleadoPvehSeeder::class,
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
     * comando); en local/test borra directamente.
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
     *
     * En desarrollo todos los seeders recrean sus datos: el wipe incluye
     * catálogos, empleados y sus asignaciones, además de roles/permisos.
     */
    public static function wipeData(): void
    {
        Schema::disableForeignKeyConstraints();

        // Orden hijos → padres para que también funcione en SQLite, donde
        // PRAGMA foreign_keys no se puede desactivar dentro de una transacción.
        foreach ([
            'reports', 'entries', 'templates', 'services',
            'model_has_roles', 'model_has_permissions', 'role_has_permissions',
            'personal_access_tokens',
        ] as $table) {
            DB::table($table)->delete();
        }

        // Autorreferencias: se limpian antes de borrar la tabla completa.
        DB::table('users')->update(['creadoPorUsuarioId' => null]);
        DB::table('users')->delete();

        DB::table('empleado_puestos')->delete();
        DB::table('empleados')->update(['creadoPorEmpleadoId' => null]);
        DB::table('empleados')->delete();
        DB::table('puestos')->delete();
        DB::table('tratamientos')->delete();
        DB::table('areas')->update(['padreId' => null]);
        DB::table('areas')->delete();

        DB::table('roles')->delete();
        DB::table('permissions')->delete();

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
