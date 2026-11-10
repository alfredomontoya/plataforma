<?php

namespace App\Support;

/**
 * (SEC-017) Escapa comodines de LIKE para que la búsqueda sea literal.
 * No sustituye el binding de parámetros: solo evita patrones % y _ del usuario.
 */
final class LikeSearch
{
    public static function contains(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
