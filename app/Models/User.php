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

    /**
     * (M12) Nota de seguridad: `isActive`, `canBackfillEnabledAt` y
     * `empleadoId` se mantienen asignables en masa porque hay código que
     * NO puede tocarse y depende de ello:
     *   - database/seeders/UsersSeeder.php: crea/actualiza cuentas vinculadas
     *     a empleados y ajusta `canBackfill` del ADMIN.
     *   - app/Services/UserService.php: User::create([... 'role','isActive']).
     * Reducir $fillable rompería silenciosamente esos flujos y los tests
     * (UsersServicesTest / AuthTest). Mitigación aplicada sin romper contratos:
     * estas columnas sólo se escriben desde endpoints protegidos por
     * `auth:sanctum` + `role:ADMIN` y validados por FormRequest; nunca provienen
     * de datos de usuario sin filtrar.
     */
    protected $fillable = [
        'username',
        'password',
        'email',
        'role',
        'isActive',
        'canBackfill',
        'canBackfillEnabledAt',
        'empleadoId',
        'creadoPorUsuarioId',
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

    /** Empleado de RRHH vinculado (1:1 obligatorio). */
    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleadoId');
    }

    public function creadoPorUsuario()
    {
        return $this->belongsTo(self::class, 'creadoPorUsuarioId');
    }

    /** Puesto actual del empleado vinculado (FK a puestos normalizados). */
    public function puestoActual()
    {
        return $this->hasOneThrough(
            EmpleadoPuesto::class,
            Empleado::class,
            'id',       // Empleado.id (clave foránea en users.empleadoId)
            'empleadoId', // EmpleadoPuesto.empleadoId
            'empleadoId', // users.empleadoId (clave local)
            'id'          // EmpleadoPuesto.id (clave local)
        )->whereNull('fechaFin');
    }

    public function getFullNameAttribute(): string
    {
        $nombre = trim("{$this->empleado?->nombre} {$this->empleado?->apellidos}");

        return $nombre !== '' ? $nombre : $this->username;
    }

    /**
     * Nombre para display: empleado de RRHH con tratamiento académico.
     */
    public function getDisplayNameAttribute(): string
    {
        $empleado = $this->empleado;

        if (! $empleado) {
            return $this->username;
        }

        $nombre = trim("{$empleado->tratamiento?->abreviatura} {$empleado->nombre} {$empleado->apellidos}");

        return $nombre !== '' ? $nombre : $this->username;
    }
}
