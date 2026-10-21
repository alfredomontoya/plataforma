<?php

namespace App\Models\Concerns;

use App\Support\MongoId;

/**
 * PK string(24) estilo MongoDB ObjectId. Usar en las tablas de dominio
 * (users, services, entries, templates, reports y las del módulo de RRHH:
 * tratamientos, areas, puestos, empleados, empleado_puestos).
 * Las tablas de paquetes (sanctum, permission) conservan su PK original.
 */
trait HasMongoId
{
    public function initializeHasMongoId(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    protected static function bootHasMongoId(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = MongoId::generate();
            }
        });
    }
}
