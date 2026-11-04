<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

/**
 * Un registro = un trámite. Reemplaza a `entries` como fuente de conteos
 * y gráficos; `entries` queda como histórico no contabilizado.
 */
class TramiteRegistro extends Model
{
    use HasMongoId;

    protected $fillable = [
        'userId', 'serviceId', 'date', 'placa', 'observaciones', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'activo' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'serviceId');
    }
}
