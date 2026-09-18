<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use HasMongoId;

    public const MODE_DAY = 'DAY';
    public const MODE_WEEK = 'WEEK';
    public const MODE_RANGE = 'RANGE';

    protected $fillable = [
        'nroCI', 'dirigidoA', 'puestoDirigidoA', 'templateId', 'mode',
        'periodStart', 'periodEnd', 'fileName', 'filePath',
        'totalIngreso', 'totalEntrega', 'generatedById',
    ];

    protected function casts(): array
    {
        return [
            'periodStart' => 'datetime',
            'periodEnd' => 'datetime',
        ];
    }

    public function template()
    {
        return $this->belongsTo(Template::class, 'templateId');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generatedById');
    }
}
