<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

class EmpleadoPuesto extends Model
{
    use HasMongoId;

    protected $fillable = [
        'empleadoId', 'puestoId', 'fechaInicio', 'fechaFin', 'supervisorId',
    ];

    protected function casts(): array
    {
        return [
            'fechaInicio' => 'date',
            'fechaFin' => 'date',
        ];
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleadoId');
    }

    public function puesto()
    {
        return $this->belongsTo(Puesto::class, 'puestoId');
    }

    public function supervisor()
    {
        return $this->belongsTo(Empleado::class, 'supervisorId');
    }

    /** true si es la asignación vigente (fechaFin NULL). */
    public function esActiva(): bool
    {
        return $this->fechaFin === null;
    }
}
