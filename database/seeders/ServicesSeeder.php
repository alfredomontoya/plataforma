<?php

namespace Database\Seeders;

use App\Models\EntregaItem;
use App\Models\Service;
use Illuminate\Database\Seeder;

class ServicesSeeder extends Seeder
{
    /** Trámites/procedimientos del catálogo: [nombre, codigo, abreviatura real]. */
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

    public function run(): void
    {
        $order = 1;

        foreach (self::DEFAULT_INGRESO as [$name, $code, $abr]) {
            $service = Service::updateOrCreate(['name' => $name], [
                'codigo' => $code,
                'abreviation' => $abr,
                'type' => Service::TYPE_INGRESO,
                'isActive' => true,
                'sortOrder' => $order++,
            ]);

            $this->syncEntregaItems($service);
        }

        // Los antiguos servicios de tipo ENTREGA pasaron a ser ítems de entrega.
        Service::where('type', Service::TYPE_ENTREGA)
            ->whereIn('codigo', ['CP', 'PM', 'PQ'])
            ->update(['isActive' => false]);
    }

    private function syncEntregaItems(Service $service): void
    {
        $codes = match (true) {
            str_starts_with($service->name, 'INSCRIPCION') => ['PL', 'PQ', 'CP'],
            str_starts_with($service->name, 'TRANSFERENCIA') => ['CP'],
            $service->name === 'CAMBIO DE RADICATORIA' => ['PQ', 'CP'],
            default => [],
        };

        $service->entregaItems()->sync(
            EntregaItem::whereIn('codigo', $codes)->pluck('id')->all()
        );
    }
}
