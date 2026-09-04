<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Fechas de negocio (docs 03): día = medianoche UTC, semana lunes–domingo,
 * display en America/La_Paz (UTC-4).
 */
final class BusinessDay
{
    public const TZ_DISPLAY = 'America/La_Paz';

    /** YYYY-MM-DD → Carbon en medianoche UTC. */
    public static function parseUtcDay(string $day): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $day, 'UTC')->startOfDay();
    }

    public static function todayUtc(): Carbon
    {
        return Carbon::now('UTC')->startOfDay();
    }

    /** Lunes de la semana (UTC) que contiene la fecha. */
    public static function weekStart(Carbon $date): Carbon
    {
        return $date->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /** Rango UTC [inicio La_Paz 00:00, fin La_Paz 00:00 día siguiente) para un día calendario Bolivia. */
    public static function laPazDayBounds(string $day): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $day, self::TZ_DISPLAY)->startOfDay()->utc();
        return [$start, $start->copy()->addDay()];
    }

    public static function displayLong(Carbon $date): string
    {
        return $date->copy()->tz(self::TZ_DISPLAY)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    }

    public static function displayShort(Carbon $date): string
    {
        return $date->copy()->tz(self::TZ_DISPLAY)->format('d/m/Y');
    }

    public static function displayCompact(Carbon $date): string
    {
        return $date->copy()->tz(self::TZ_DISPLAY)->format('d/m/y');
    }

    public static function trend(?int $current, ?int $previous): ?float
    {
        if (! $previous) {
            return null;
        }
        return round(($current - $previous) / $previous * 100, 1);
    }
}
