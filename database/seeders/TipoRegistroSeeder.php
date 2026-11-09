<?php

namespace Database\Seeders;

use App\Models\TipoRegistro;
use Illuminate\Database\Seeder;

class TipoRegistroSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            ['name' => 'INGRESO', 'codigo' => 'INGRESO', 'requiereItems' => false, 'requiereTicket' => true, 'sortOrder' => 1],
            ['name' => 'ENTREGA', 'codigo' => 'ENTREGA', 'requiereItems' => true, 'requiereTicket' => true, 'sortOrder' => 2],
            ['name' => 'CIERRE', 'codigo' => 'CIERRE', 'requiereItems' => false, 'requiereTicket' => false, 'sortOrder' => 3],
        ];

        foreach ($tipos as $tipo) {
            TipoRegistro::updateOrCreate(
                ['codigo' => $tipo['codigo']],
                $tipo + ['isActive' => true],
            );
        }
    }
}
