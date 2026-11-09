<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un registro = un trámite. Reemplaza a `entries` como fuente de conteos
 * y gráficos; `entries` queda como histórico no contabilizado.
 */
class TramiteRegistro extends Model
{
    use HasMongoId, HasFactory;

    protected $fillable = [
        'userId',
        'serviceId',
        'tipoRegistroId',
        'date',
        'placa',
        'observaciones',
        'activo',
        'ticket',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'activo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Compatibilidad: los registros antiguos no traían tipo de registro.
        static::creating(function (TramiteRegistro $registro) {
            if (! $registro->tipoRegistroId && $registro->serviceId) {
                $type = Service::whereKey($registro->serviceId)->value('type');

                if ($type) {
                    $registro->tipoRegistroId = TipoRegistro::where('codigo', $type)->value('id');
                }
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'serviceId');
    }

    public function tipoRegistro()
    {
        return $this->belongsTo(TipoRegistro::class, 'tipoRegistroId');
    }

    public function entregaItems()
    {
        return $this->belongsToMany(
            EntregaItem::class,
            'tramite_registro_entrega_items',
            'tramiteRegistroId',
            'entregaItemId',
        );
    }
}
