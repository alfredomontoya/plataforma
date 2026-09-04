<?php

namespace App\Support;

/**
 * PRNG determinista mulberry32 (docs 03 § Seeders): mismo seed = mismos datos.
 * Todos los sorteos se hacen incondicionalmente para idempotencia.
 */
final class Mulberry32
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0xFFFFFFFF;
    }

    public function next(): float
    {
        $this->state = ($this->state + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->state;
        $t = ($t ^ ($t >> 15)) & 0xFFFFFFFF;
        $t = ($t * ($t | 1)) & 0xFFFFFFFF;
        $t ^= $t + (($t ^ ($t >> 7)) & 0xFFFFFFFF);
        $t = ($t ^ ($t >> 14)) & 0xFFFFFFFF;

        return $t / 4294967296;
    }

    public function int(int $min, int $max): int
    {
        return $min + (int) floor($this->next() * ($max - $min + 1));
    }

    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /** Fisher-Yates con este PRNG. */
    public function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
