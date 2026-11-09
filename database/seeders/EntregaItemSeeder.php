<?php

namespace Database\Seeders;

use App\Models\EntregaItem;
use Illuminate\Database\Seeder;

class EntregaItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['name' => 'PLACA', 'codigo' => 'PL', 'sortOrder' => 1],
            ['name' => 'CERTIFICADO DE PROPIEDAD', 'codigo' => 'CP', 'sortOrder' => 2],
            ['name' => 'PLAQUETA', 'codigo' => 'PQ', 'sortOrder' => 3],
        ];

        foreach ($items as $item) {
            EntregaItem::updateOrCreate(
                ['codigo' => $item['codigo']],
                $item + ['isActive' => true],
            );
        }
    }
}
