<?php

namespace Database\Seeders;

use App\Models\EntregaItem;
use App\Models\TipoRegistro;
use App\Models\TramiteRegistro;
use Illuminate\Database\Seeder;

class TramiteRegistroSeeder extends Seeder
{
    public function run(): void
    {
        $registros = [
            ['2026-11-04', '1234567', null],
            ['2026-11-04', '21234SBB', null],
            ['2026-11-05', '1234ABC', null],
            ['2026-11-05', '1234567', 'sdfasfsf'],
            ['2026-11-05', '1234XXX', null],
            ['2026-11-05', '1111AAA', null],
            ['2026-11-05', '1112AAB', null],
            ['2026-11-05', '1113AAB', null],
            ['2026-11-05', '1114XXX', null],
            ['2026-11-05', '1114XYX', null],
            ['2026-11-05', '1119XYY', null],
            ['2026-11-05', '1122BBB', null],
            ['2026-11-05', '2233BBB', null],
            ['2026-11-05', '2211BBN', null],
            ['2026-11-05', '1234BBB', null],
            ['2026-11-05', '3214BBB', null],
            ['2026-11-05', '1132BBB', 'A solicilitud del jefe'],
            ['2026-11-05', '1234AAA', null],
            ['2026-11-05', '1234AAA', 'asdfasdfas a'],
            ['2026-11-05', '1123BBB', null],
            ['2026-11-05', '2222SSS', null],
            ['2026-11-05', '1234XXX', 'asdfasdf'],
            ['2026-11-05', '5021XFF', 'a solicitud de ydderf velazco, sinficha'],
        ];

        $tipoIngreso = TipoRegistro::where('codigo', TipoRegistro::CODE_INGRESO)->value('id');
        $tipoEntrega = TipoRegistro::where('codigo', TipoRegistro::CODE_ENTREGA)->value('id');
        $items = EntregaItem::where('isActive', true)->get();

        foreach ($registros as $index => [$fecha, $placa, $observaciones]) {
            $esEntrega = $index % 2 === 0;
            $tipoId = $esEntrega ? $tipoEntrega : $tipoIngreso;

            $registro = TramiteRegistro::factory()->create([
                'date' => $fecha.' 00:00:00',
                'placa' => $placa,
                'observaciones' => $observaciones,
                'ticket' => null,
                'estado' => 'procesado',
                'activo' => true,
                'tipoRegistroId' => $tipoId,
            ]);

            if ($esEntrega && $items->isNotEmpty()) {
                $registro->entregaItems()->sync(
                    $items->random(min(2, $items->count()))->pluck('id')->all()
                );
            }
        }
    }
}
