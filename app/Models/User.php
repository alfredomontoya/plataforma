<?php

namespace App\Models;

use App\Models\Concerns\HasMongoId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasMongoId, HasRoles, Notifiable;

    protected $fillable = [
        'username',
        'password',
        'role',
        'title',
        'firstName',
        'lastName',
        'paternalSurname',
        'maternalSurname',
        'isActive',
        'canBackfill',
        'canBackfillEnabledAt',
        'activePositionId',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'isActive' => 'boolean',
            'canBackfill' => 'boolean',
            'canBackfillEnabledAt' => 'date',
        ];
    }

    public function positions()
    {
        return $this->hasMany(Position::class, 'userId');
    }

    public function activePosition()
    {
        return $this->belongsTo(Position::class, 'activePositionId');
    }

    public function entries()
    {
        return $this->hasMany(Entry::class, 'userId');
    }

    public function templates()
    {
        return $this->hasMany(Template::class, 'uploadedById');
    }

    public function reports()
    {
        return $this->hasMany(Report::class, 'generatedById');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->firstName} {$this->lastName} {$this->paternalSurname} {$this->maternalSurname}");
    }
}
