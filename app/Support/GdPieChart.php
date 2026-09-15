<?php

namespace App\Support;

/**
 * Torta PNG con GD para incrustar en el .docx (docs 03 § Informes).
 * Etiquetas abreviatura + % (las reglas finas de la UI viven en el cliente).
 */
final class GdPieChart
{
    private const PALETTE = [
        [247, 31, 61], [37, 99, 235], [22, 163, 74], [217, 119, 6],
        [147, 51, 234], [13, 148, 136], [236, 72, 153], [234, 88, 12],
        [8, 145, 178], [101, 163, 13], [79, 70, 229], [120, 113, 108],
    ];

    /** @param array{labels: string[], values: int[]} $data */
    public static function render(array $data, string $path, int $width = 640, int $height = 400): string
    {
        $img = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        $dark = imagecolorallocate($img, 60, 60, 60);
        imagefill($img, 0, 0, $white);

        $total = max(1, array_sum($data['values']));
        $cx = (int) ($width * 0.36);
        $cy = (int) ($height / 2);
        $d = min($width * 0.55, $height * 0.8);

        $angle = 0;
        $legendY = 24;
        foreach ($data['values'] as $i => $value) {
            [$r, $g, $b] = self::PALETTE[$i % count(self::PALETTE)];
            $color = imagecolorallocate($img, $r, $g, $b);
            $sweep = $value / $total * 360;
            imagefilledarc($img, $cx, $cy, (int) $d, (int) $d, (int) $angle, (int) ($angle + $sweep), $color, IMG_ARC_PIE);
            $angle += $sweep;

            $pct = round($value / $total * 100, 1);
            $lx = (int) ($width * 0.68);
            imagefilledrectangle($img, $lx, $legendY, $lx + 14, $legendY + 14, $color);
            imagestring($img, 3, $lx + 20, $legendY, substr(($data['labels'][$i] ?? "?") . " {$pct}%", 0, 32), $dark);
            $legendY += 22;
        }

        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
