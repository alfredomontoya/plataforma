<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServicesSeeder extends Seeder
{
    /** 12 INGRESO + 3 ENTREGA (docs 03 § Servicios): [nombre, codigo, abreviatura real]. */
    public const DEFAULT_INGRESO = [
        ['INSCRIPCION IMPORTACION DIRECTA', 'IID', 'Insc. Imp. Dir.'],
        ['INSCRIPCION CASA COMERCIAL', 'ICC', 'Insc. Casa Com.'],
        ['INSCRIPCION IMPORTACION DIRECTA CON TRANSFERENCIA', 'IIDT', 'Insc. Imp. Dir. c/ Transf.'],
        ['TRANSFERENCIA NORMAL', 'TN', 'Transf. Norm.'],
        ['TRANSFERENCIA ESPECIAL', 'TE', 'Transf. Esp.'],
        ['CAMBIO DE RADICATORIA', 'CR', 'Cambio Rad.'],
        ['DUPLICADO DE CERTIFICADO DE PROPIEDAD', 'DCP', 'Dup. Cert. Prop.'],
        ['DUPLICADO DE PLACA VEHICULO', 'DPV', 'Dup. Placa Veh.'],
        ['DUPLICADO DE PLACA MOTO', 'DPM', 'Dup. Placa Moto'],
        ['MODIFICACION DE DATOS TECNICOS', 'MDT', 'Mod. Datos Téc.'],
        ['BAJA TEMPORAL', 'BT', 'Baja Temp.'],
        ['BAJA DEFINITIVA', 'BD', 'Baja Def.'],
    ];

    public const DEFAULT_ENTREGA = [
        ['CERTIFICADO DE PROPIEDAD', 'CP', 'Cert. Prop.'],
        ['PLACA METALICA', 'PM', 'Placa Met.'],
        ['PLAQUETA', 'PQ', 'Plaq.'],
    ];

    public function run(): void
    {
        $order = 1;
        foreach (self::DEFAULT_INGRESO as [$name, $code, $abr]) {
            Service::updateOrCreate(['name' => $name], [
                'codigo' => $code, 'abreviation' => $abr, 'type' => 'INGRESO', 'isActive' => true, 'sortOrder' => $order++,
            ]);
        }
        foreach (self::DEFAULT_ENTREGA as [$name, $code, $abr]) {
            Service::updateOrCreate(['name' => $name], [
                'codigo' => $code, 'abreviation' => $abr, 'type' => 'ENTREGA', 'isActive' => true, 'sortOrder' => $order++,
            ]);
        }
    }
}
