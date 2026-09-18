<?php

namespace App\Support;

/**
 * Barras horizontales PNG con GD para el .docx (docs 03 § Informes).
 * Misma configuración que el dashboard: una serie morada, etiquetas
 * de trámite a la izquierda y valores al final de cada barra.
 */
final class GdBarChart
{
    /** Misma paleta categórica del dashboard. */
    private const PALETTE = [
        [247, 31, 61], [37, 99, 235], [22, 163, 74], [217, 119, 6],
        [147, 51, 234], [13, 148, 136], [236, 72, 153], [234, 88, 12],
        [8, 145, 178], [101, 163, 13], [79, 70, 229], [120, 113, 108],
    ];

    /**
     * @param  array{labels: string[], values: int[]}  $data
     */
    public static function render(array $data, string $path, int $width = 1200, ?int $height = null): string
    {
        $labels = array_map(fn ($l) => mb_substr((string) $l, 0, 40), $data['labels']);
        $values = array_map('intval', $data['values']);
        $n = max(1, count($labels));

        $pl = 300;
        $pr = 70;
        $pt = 24;
        $rowH = 34;
        $pb = 30;
        $height ??= $pt + $n * $rowH + $pb;
        $ph = $height - $pt - $pb;
        $pw = $width - $pl - $pr;

        $img = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        $dark = imagecolorallocate($img, 80, 80, 80);
        $grid = imagecolorallocate($img, 226, 226, 226);
        $bars = [];
        foreach (self::PALETTE as [$br, $bg, $bb]) {
            $bars[] = imagecolorallocate($img, $br, $bg, $bb);
        }
        imagefill($img, 0, 0, $white);

        // Techo cercano (282 → 300, no 500) para que los valores chicos se vean.
        $maxV = max(1, ...$values);
        $exp = (int) floor(log10($maxV));
        $base = 10 ** $exp;
        $top = $maxV;
        foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $s) {
            if ($s * $base >= $maxV) {
                $top = (int) round($s * $base);
                break;
            }
        }

        // Grilla vertical + escala (paso que divide el techo en ≤6 intervalos).
        $stepExp = (int) floor(log10(max(1, $top / 6)));
        $stepBase = 10 ** $stepExp;
        $step = $stepBase;
        foreach ([1, 2, 2.5, 5, 10] as $s) {
            if ($top / ($s * $stepBase) <= 6) {
                $step = (int) round($s * $stepBase);
                break;
            }
        }
        for ($val = 0; $val <= $top; $val += $step) {
            $x = (int) ($pl + $val / $top * $pw);
            imageline($img, $x, $pt, $x, $height - $pb, $grid);
            imagestring($img, 2, $x - 5, $height - $pb + 8, (string) $val, $dark);
        }

        $bh = min(20, (int) ($rowH * 0.55));
        foreach ($labels as $i => $label) {
            $y = $pt + (int) ($i * $rowH + $rowH / 2);
            imagestring($img, 3, 8, $y - 6, $label, $dark);
            $bw = (int) ($values[$i] / $top * $pw);
            $bar = $bars[$i % count($bars)];
            if ($bw > 0) {
                imagefilledrectangle($img, $pl, $y - (int) ($bh / 2), $pl + $bw, $y + (int) ($bh / 2), $bar);
            }
            $v = (string) $values[$i];
            imagestring($img, 3, $pl + $bw + 6, $y - 6, $v, $dark);
        }

        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
