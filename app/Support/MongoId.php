<?php

namespace App\Support;

/**
 * Generador de identificadores de 24 caracteres hexadecimales,
 * compatibles en formato con MongoDB ObjectId:
 * 4 bytes timestamp + 5 bytes aleatorios + 3 bytes contador.
 * Se almacenan como string(24) en MySQL (CHAR(24), PK no incremental).
 */
final class MongoId
{
    private static int $counter = 0;

    public static function generate(): string
    {
        self::$counter = (self::$counter + 1) % 0xFFFFFF;

        return sprintf(
            '%08x%010x%06x',
            time(),
            random_int(0, 0xFFFFFFFFFF),
            self::$counter
        );
    }

    public static function isValid(string $id): bool
    {
        return (bool) preg_match('/\A[0-9a-f]{24}\z/', $id);
    }
}
