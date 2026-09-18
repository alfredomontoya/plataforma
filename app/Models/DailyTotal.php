<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Model;

class DailyTotal extends Model
{
    use HasMongoId;

    protected $fillable = ['userId', 'serviceId', 'date', 'quantity'];

    protected function casts(): array
    {
        return ['date' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'serviceId');
    }
}
