<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EntregaItem extends Model
{
    use HasMongoId;

    protected $table = 'entrega_items';

    protected $fillable = [
        'name',
        'codigo',
        'isActive',
        'sortOrder',
    ];

    protected function casts(): array
    {
        return [
            'isActive' => 'boolean',
        ];
    }

    public function services()
    {
        return $this->belongsToMany(
            Service::class,
            'service_entrega_items',
            'entregaItemId',
            'serviceId',
        );
    }

    public function tramiteRegistros()
    {
        return $this->belongsToMany(
            TramiteRegistro::class,
            'tramite_registro_entrega_items',
            'entregaItemId',
            'tramiteRegistroId',
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('isActive', true);
    }
}
