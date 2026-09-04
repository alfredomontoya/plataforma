<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use HasMongoId;

    protected $fillable = [
        'name', 'fileName', 'filePath', 'uploadedById', 'isDefault',
    ];

    protected function casts(): array
    {
        return ['isDefault' => 'boolean'];
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploadedById');
    }

    public function reports()
    {
        return $this->hasMany(Report::class, 'templateId');
    }
}
