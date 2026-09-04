<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasMongoId;

    public const TYPE_INGRESO = 'INGRESO';
    public const TYPE_ENTREGA = 'ENTREGA';

    protected $fillable = [
        'name', 'abreviation', 'type', 'isActive', 'sortOrder',
    ];

    protected function casts(): array
    {
        return ['isActive' => 'boolean'];
    }

    public function entries()
    {
        return $this->hasMany(Entry::class, 'serviceId');
    }
}
