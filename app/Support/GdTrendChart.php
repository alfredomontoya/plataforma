<?php

namespace App\Support;

/**
 * Tendencia diaria PNG con GD para incrustar en el .docx (docs 03 § Informes).
 * Misma configuración que el dashboard: series Ingreso (azul) y Entrega
 * (verde), grilla punteada, leyenda superior y valores sobre cada punto.
 */
final class GdTrendChart
{
    /**
     * @param  array<int, array<string, mixed>>  $points
     * @param  array<int, array{0: string, 1: string, 2: array{0: int, 1: int, 2: int}}>|null  $series
     *   Series [key, nombre, rgb]. Default: Ingreso (azul) + Entrega (verde).
     */
    public static function render(array $points, string $path, int $width = 900, int $height = 430, ?array $series = null): string
    {
        $series ??= [['ingreso', 'Ingreso', [37, 99, 235]], ['entrega', 'Entrega', [22, 163, 74]]];
        $img = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        $dark = imagecolorallocate($img, 80, 80, 80);
        $grid = imagecolorallocate($img, 226, 226, 226);
        $colors = [];
        foreach ($series as [$key, , $rgb]) {
            $colors[$key] = [
                imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]),
                imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], 90),
            ];
        }
        imagefill($img, 0, 0, $white);

        $pl = 48;
        $pr = 24;
        $pt = 56;
        $pb = 44;
        $pw = $width - $pl - $pr;
        $ph = $height - $pt - $pb;

        $keys = array_map(fn ($s) => $s[0], $series);
        $maxV = 0;
        foreach ($points as $p) {
            foreach ($keys as $k) {
                $maxV = max($maxV, (int) ($p[$k] ?? 0));
            }
        }
        $maxV = max(1, $maxV);
        $scale = 10 ** floor(log10($maxV));
        $top = $maxV;
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $scale >= $maxV) {
                $top = $m * $scale;
                break;
            }
        }

        // Grilla horizontal punteada + escala
        $ticks = 5;
        for ($i = 0; $i <= $ticks; $i++) {
            $val = $top * $i / $ticks;
            $y = (int) ($pt + $ph - $val / $top * $ph);
            imageline($img, $pl, $y, $width - $pr, $y, $grid);
            imagestring($img, 2, 6, $y - 5, (string) (int) round($val), $dark);
        }

        $n = count($points);
        $xAt = function (int $i) use ($pl, $pw, $n): int {
            return $n === 1 ? $pl + (int) ($pw / 2) : $pl + (int) round($i / ($n - 1) * $pw);
        };
        $yAt = function (int $v) use ($pt, $ph, $top): int {
            return (int) ($pt + $ph - $v / max(1, $top) * $ph);
        };

        // Etiquetas del eje X (día del mes)
        $step = max(1, (int) ceil($n / 18));
        foreach ($points as $i => $p) {
            if ($i % $step === 0) {
                imagestring($img, 2, $xAt($i) - 5, $height - $pb + 8, (string) ($p['day'] ?? ''), $dark);
            }
        }

        // Leyenda superior centrada
        $legendWidth = 0;
        foreach ($series as [, $name]) {
            $legendWidth += 30 + strlen($name) * 7 + 36;
        }
        $legendWidth -= 36;
        $lx = (int) (($width - $legendWidth) / 2);
        foreach ($series as [$key, $name]) {
            $c = $colors[$key][0];
            imagefilledrectangle($img, $lx, 20, $lx + 13, 32, $c);
            imagestring($img, 3, $lx + 17, 18, $name, $dark);
            $lx += 30 + strlen($name) * 7 + 36;
        }

        // Series: área con transparencia + línea + puntos + valores
        foreach ($series as [$key]) {
            [$c, $fill] = $colors[$key];
            $pts = [];
            foreach ($points as $i => $p) {
                $pts[] = [$xAt($i), $yAt((int) ($p[$key] ?? 0))];
            }
            if ($n === 0) {
                continue;
            }

            $poly = [];
            foreach ($pts as [$x, $y]) {
                $poly[] = $x;
                $poly[] = $y;
            }
            $poly[] = $pts[$n - 1][0];
            $poly[] = $pt + $ph;
            $poly[] = $pts[0][0];
            $poly[] = $pt + $ph;
            imagefilledpolygon($img, $poly, (int) (count($poly) / 2), $fill);

            for ($i = 1; $i < $n; $i++) {
                imageline($img, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], $c);
            }

            foreach ($pts as $i => [$x, $y]) {
                imagefilledellipse($img, $x, $y, 7, 7, $c);
                $v = (int) ($points[$i][$key] ?? 0);
                if ($v > 0) {
                    $label = (string) $v;
                    imagestring($img, 2, $x - (int) (strlen($label) * 3.5), $y - 11, $label, $c);
                }
            }
        }

        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
