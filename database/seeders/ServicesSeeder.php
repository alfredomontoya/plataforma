<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServicesSeeder extends Seeder
{
    /** 12 INGRESO + 3 ENTREGA (docs 03 § Servicios). */
    public const DEFAULT_INGRESO = [
        ['INSCRIPCION IMPORTACION DIRECTA', 'IID'],
        ['INSCRIPCION CASA COMERCIAL', 'ICC'],
        ['INSCRIPCION IMPORTACION DIRECTA CON TRANSFERENCIA', 'IIDT'],
        ['TRANSFERENCIA NORMAL', 'TN'],
        ['TRANSFERENCIA ESPECIAL', 'TE'],
        ['CAMBIO DE RADICATORIA', 'CR'],
        ['DUPLICADO DE CERTIFICADO DE PROPIEDAD', 'DCP'],
        ['DUPLICADO DE PLACA VEHICULO', 'DPV'],
        ['DUPLICADO DE PLACA MOTO', 'DPM'],
        ['MODIFICACION DE DATOS TECNICOS', 'MDT'],
        ['BAJA TEMPORAL', 'BT'],
        ['BAJA DEFINITIVA', 'BD'],
    ];

    public const DEFAULT_ENTREGA = [
        ['CERTIFICADO DE PROPIEDAD', 'CP'],
        ['PLACA METALICA', 'PM'],
        ['PLAQUETA', 'PQ'],
    ];

    public function run(): void
    {
        $order = 1;
        foreach (self::DEFAULT_INGRESO as [$name, $abr]) {
            Service::updateOrCreate(['name' => $name], [
                'abreviation' => $abr, 'type' => 'INGRESO', 'isActive' => true, 'sortOrder' => $order++,
            ]);
        }
        foreach (self::DEFAULT_ENTREGA as [$name, $abr]) {
            Service::updateOrCreate(['name' => $name], [
                'abreviation' => $abr, 'type' => 'ENTREGA', 'isActive' => true, 'sortOrder' => $order++,
            ]);
        }
    }
}
