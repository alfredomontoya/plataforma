<?php

namespace App\Support;

/**
 * Torta PNG con GD para incrustar en el .docx (docs 03 § Informes).
 * Misma configuración que el dashboard: etiqueta por sector (nombre + %) y
 * leyenda derecha con nombre completo y porcentaje entre paréntesis.
 */
final class GdPieChart
{
    private const PALETTE = [
        [247, 31, 61], [37, 99, 235], [22, 163, 74], [217, 119, 6],
        [147, 51, 234], [13, 148, 136], [236, 72, 153], [234, 88, 12],
        [8, 145, 178], [101, 163, 13], [79, 70, 229], [120, 113, 108],
    ];

    /**
     * @param  array{labels: string[], values: int[], legendLabels?: string[]}  $data
     */
    public static function render(array $data, string $path, int $width = 760, int $height = 420): string
    {
        $img = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        $dark = imagecolorallocate($img, 60, 60, 60);
        imagefill($img, 0, 0, $white);

        $values = array_map('intval', $data['values']);
        $labels = $data['labels'];
        $legendLabels = $data['legendLabels'] ?? $labels;
        $total = max(1, array_sum($values));

        $cx = (int) ($width * 0.34);
        $cy = (int) ($height / 2);
        $d = min($width * 0.5, $height * 0.78);
        $legendX = (int) ($width * 0.60);
        $labelR = $d * 0.42;
        $labelChars = (int) (($legendX - $cx - $labelR - 12) / 6.5);
        $legendChars = (int) (($width - $legendX - 20) / 6.5);

        $angle = 0;
        $legendY = 26;
        foreach ($values as $i => $value) {
            [$r, $g, $b] = self::PALETTE[$i % count(self::PALETTE)];
            $color = imagecolorallocate($img, $r, $g, $b);

            $sweep = $value / $total * 360;
            imagefilledarc($img, $cx, $cy, (int) $d, (int) $d, (int) $angle, (int) ($angle + $sweep), $color, IMG_ARC_PIE);

            // Etiqueta del sector, como la label del dashboard: "Nombre 12.5%"
            $pct = number_format($value / $total * 100, 1);
            $mid = deg2rad($angle + $sweep / 2);
            $sliceLabel = self::clip(($labels[$i] ?? '?').' '.$pct.'%', $labelChars);
            $tx = $cx + (int) (cos($mid) * $labelR);
            $ty = $cy + (int) (sin($mid) * $labelR);
            imagestring($img, 3, $tx - (int) (strlen($sliceLabel) * 6.5 / 2), $ty - 6, $sliceLabel, $dark);

            // Leyenda derecha, como la del dashboard: "Nombre completo (12.5%)"
            imagefilledrectangle($img, $legendX, $legendY, $legendX + 14, $legendY + 14, $color);
            $legend = self::clip(($legendLabels[$i] ?? $labels[$i] ?? '?')." ({$pct}%)", $legendChars);
            imagestring($img, 3, $legendX + 20, $legendY, $legend, $dark);
            $legendY += 22;

            $angle += $sweep;
        }

        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    /** Recorta el texto conservando la cola (la parte relevante al final). */
    private static function clip(string $text, int $max): string
    {
        $max = max(4, $max);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 5).'...'.mb_substr($text, -2);
    }
}
