<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public const ROLES = ['ADMIN', 'OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'JEFE'];

    public const PERMISSIONS = [
        'entries.create.ingreso',
        'entries.create.entrega',
        'entries.view.own',
        'entries.view.all',
        'dashboard.view',
        'reports.generate',
        'templates.manage',
        'users.manage',
        'services.manage',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // ADMIN = todo (docs 03: `*`)
        Role::findByName('ADMIN', 'web')->syncPermissions(self::PERMISSIONS);
        Role::findByName('OPERATOR_INGRESO', 'web')->syncPermissions([
            'entries.create.ingreso', 'entries.view.own',
        ]);
        Role::findByName('OPERATOR_ENTREGA', 'web')->syncPermissions([
            'entries.create.entrega', 'entries.view.own',
        ]);
        Role::findByName('JEFE', 'web')->syncPermissions([
            'entries.view.all', 'dashboard.view', 'reports.generate', 'templates.manage',
        ]);
    }
}
