<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

class Puesto extends Model
{
    use HasMongoId;

    protected $fillable = [
        'areaId', 'nombre', 'sigla', 'nivel', 'esSupervisor',
    ];

    protected function casts(): array
    {
        return [
            'nivel' => 'integer',
            'esSupervisor' => 'boolean',
        ];
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'areaId');
    }

    public function asignaciones()
    {
        return $this->hasMany(EmpleadoPuesto::class, 'puestoId');
    }
}
