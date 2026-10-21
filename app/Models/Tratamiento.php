<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

class Tratamiento extends Model
{
    use HasMongoId;

    protected $fillable = [
        'nombre', 'abreviatura', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function empleados()
    {
        return $this->hasMany(Empleado::class, 'tratamientoId');
    }
}
