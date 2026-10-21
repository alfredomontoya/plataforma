<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    use HasMongoId;

    protected $fillable = [
        'nombre', 'codigo', 'descripcion', 'padreId',
    ];

    public function puestos()
    {
        return $this->hasMany(Puesto::class, 'areaId');
    }

    /** Área de la que depende esta área (jerarquía organizacional). */
    public function padre()
    {
        return $this->belongsTo(self::class, 'padreId');
    }

    public function hijos()
    {
        return $this->hasMany(self::class, 'padreId');
    }
}
