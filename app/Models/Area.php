<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    use HasMongoId;

    protected $fillable = [
        'nombre', 'descripcion',
    ];

    public function puestos()
    {
        return $this->hasMany(Puesto::class, 'areaId');
    }
}
