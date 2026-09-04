<?php

namespace App\Models\Concerns;

use App\Support\MongoId;

/**
 * PK string(24) estilo MongoDB ObjectId. Usar en las 6 tablas de dominio
 * (users, positions, services, entries, templates, reports).
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
