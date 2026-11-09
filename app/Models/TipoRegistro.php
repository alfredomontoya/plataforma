<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TipoRegistro extends Model
{
    use HasMongoId;

    public const CODE_INGRESO = 'INGRESO';
    public const CODE_ENTREGA = 'ENTREGA';
    public const CODE_CIERRE = 'CIERRE';

    protected $table = 'tipo_registros';

    protected $fillable = [
        'name',
        'codigo',
        'requiereItems',
        'requiereTicket',
        'isActive',
        'sortOrder',
    ];

    protected function casts(): array
    {
        return [
            'requiereItems' => 'boolean',
            'requiereTicket' => 'boolean',
            'isActive' => 'boolean',
        ];
    }

    public function tramiteRegistros()
    {
        return $this->hasMany(TramiteRegistro::class, 'tipoRegistroId');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('isActive', true);
    }
}
