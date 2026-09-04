<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    use HasMongoId;

    protected $fillable = [
        'userId', 'title', 'position', 'department',
        'isActive', 'startDate', 'endDate',
    ];

    protected function casts(): array
    {
        return [
            'isActive' => 'boolean',
            'startDate' => 'datetime',
            'endDate' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
