<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    use HasMongoId;

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_INACTIVO = 'inactivo';

    protected $fillable = [
        'tratamientoId', 'ci', 'nombre', 'apellidos', 'fechaNacimiento',
        'telefono', 'direccion', 'profesion', 'estado', 'creadoPorEmpleadoId',
    ];

    protected function casts(): array
    {
        return ['fechaNacimiento' => 'date'];
    }

    public function tratamiento()
    {
        return $this->belongsTo(Tratamiento::class, 'tratamientoId');
    }

    public function creadoPor()
    {
        return $this->belongsTo(self::class, 'creadoPorEmpleadoId');
    }

    /** Puesto activo actual (asignación con fechaFin NULL). */
    public function puestoActual()
    {
        return $this->hasOne(EmpleadoPuesto::class, 'empleadoId')->whereNull('fechaFin');
    }

    public function asignaciones()
    {
        return $this->hasMany(EmpleadoPuesto::class, 'empleadoId');
    }

    /** Cuenta de sistema vinculada (1:1, opcional). */
    public function usuario()
    {
        return $this->hasOne(User::class, 'empleadoId');
    }

    public function esActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }
}
