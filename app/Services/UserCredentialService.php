<?php

namespace App\Services;

use App\Models\Empleado;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Genera credenciales de sistema a partir de la ficha del empleado.
 *
 * Usuario/correo: iniciales de todos los nombres + primer apellido completo.
 * Ej.: Jose Antonio Perez Mendez → japerez. Los duplicados agregan 2, 3, etc.
 */
class UserCredentialService
{
    public const DOMAIN = 'plataforma.test';

    /**
     * @return array{username: string, email: string}
     */
    public function suggestFor(Empleado $empleado): array
    {
        return $this->uniqueCredentials($this->baseUsername($empleado));
    }

    public function baseUsername(Empleado $empleado): string
    {
        $nombres = preg_split('/\s+/u', trim((string) $empleado->nombre)) ?: [];
        $iniciales = '';

        foreach ($nombres as $nombre) {
            $limpio = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($nombre))) ?? '';

            if ($limpio !== '') {
                $iniciales .= $limpio[0];
            }
        }

        $apellidos = preg_split('/\s+/u', trim((string) $empleado->apellidos)) ?: [];
        $primerApellido = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($apellidos[0] ?? ''))) ?? '';
        $base = $iniciales.$primerApellido;

        $ci = preg_replace('/[^a-z0-9]/', '', Str::lower((string) $empleado->ci)) ?? '';

        if (strlen($base) < 3) {
            $base .= $ci;
        }

        return $base !== '' ? $base : 'empleado'.$ci;
    }

    /**
     * @return array{username: string, email: string}
     */
    public function uniqueCredentials(string $base): array
    {
        $intento = 1;

        do {
            $username = $intento === 1 ? $base : $base.$intento;
            $email = $username.'@'.self::DOMAIN;
            $existe = User::where(function ($query) use ($username, $email) {
                $query->where('username', $username)->orWhere('email', $email);
            })->exists();
            $intento++;
        } while ($existe);

        return ['username' => $username, 'email' => $email];
    }
}
