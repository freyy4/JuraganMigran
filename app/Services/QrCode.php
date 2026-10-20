<?php

namespace App\Services;

/** QR version 10, error correction L, byte mode. The payload is only a verification URL. */
class QrCode
{
    private array $modules = [];

    private array $reserved = [];

    private function set(int $x, int $y, bool $value): void
    {
        if ($x >= 0 && $x < 57 && $y >= 0 && $y < 57) {
            $this->modules[$y][$x] = $value;
            $this->reserved[$y][$x] = true;
        }
    }

    private function multiply(int $a, int $b): int
    {
        $v = 0;
        for ($i = 7; $i >= 0; $i--) {
            $v = ($v << 1) ^ (($v >> 7) * 0x11D);
            $v ^= (($b >> $i) & 1) * $a;
        }

        return $v;
    }

    private function parity(array $data): array
    {
        $divisor = array_fill(0, 18, 0);
        $divisor[17] = 1;
        $root = 1;
        for ($i = 0; $i < 18; $i++) {
            for ($j = 0; $j < 18; $j++) {
                $divisor[$j] = $this->multiply($divisor[$j], $root);
                if ($j < 17) {
                    $divisor[$j] ^= $divisor[$j + 1];
                }
            }$root = $this->multiply($root, 2);
        }$result = array_fill(0, 18, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < 18; $i++) {
                $result[$i] ^= $this->multiply($divisor[$i], $factor);
            }
        }

        return $result;
    }

    private function append(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    public function svg(string $payload): string
    {
        if (strlen($payload) > 271) {
            throw new \InvalidArgumentException('URL QR terlalu panjang.');
        }$this->modules = array_fill(0, 57, array_fill(0, 57, false));
        $this->reserved = $this->modules;
        for ($i = 0; $i < 57; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [53, 3], [3, 53]] as [$cx,$cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $d = max(abs($dx), abs($dy));
                    $this->set($cx + $dx, $cy + $dy, $d !== 2 && $d !== 4);
                }
            }
        }
        foreach ([6, 28, 50] as $cy) {
            foreach ([6, 28, 50] as $cx) {
                if (($cx === 6 && $cy === 6) || ($cx === 6 && $cy === 50) || ($cx === 50 && $cy === 6)) {
                    continue;
                }for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $format = (1 << 3);
        $rem = $format;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }$format = (($format << 10) | $rem) ^ 0x5412;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, (bool) (($format >> $i) & 1));
        }$this->set(8, 7, (bool) (($format >> 6) & 1));
        $this->set(8, 8, (bool) (($format >> 7) & 1));
        $this->set(7, 8, (bool) (($format >> 8) & 1));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, (bool) (($format >> $i) & 1));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set(56 - $i, 8, (bool) (($format >> $i) & 1));
        }for ($i = 8; $i < 15; $i++) {
            $this->set(8, 42 + $i, (bool) (($format >> $i) & 1));
        }$this->set(8, 49, true);
        $rem = 10;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }$version = (10 << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $a = 46 + $i % 3;
            $b = intdiv($i, 3);
            $v = (bool) (($version >> $i) & 1);
            $this->set($a, $b, $v);
            $this->set($b, $a, $v);
        }
        $bits = [];
        $this->append($bits, 4, 4);
        $this->append($bits, strlen($payload), 16);
        foreach (unpack('C*', $payload) as $byte) {
            $this->append($bits, $byte, 8);
        }for ($i = 0; $i < min(4, 2192 - count($bits)); $i++) {
            $bits[] = 0;
        }while (count($bits) % 8) {
            $bits[] = 0;
        }$data = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }$data[] = $byte;
        }for ($i = 0; count($data) < 274; $i++) {
            $data[] = $i % 2 === 0 ? 0xEC : 0x11;
        }
        $blocks = [];
        $ecc = [];
        $offset = 0;
        foreach ([68, 68, 69, 69] as $len) {
            $block = array_slice($data, $offset, $len);
            $offset += $len;
            $blocks[] = $block;
            $ecc[] = $this->parity($block);
        }$code = [];
        for ($i = 0; $i < 69; $i++) {
            foreach ($blocks as $b) {
                if (isset($b[$i])) {
                    $code[] = $b[$i];
                }
            }
        }for ($i = 0; $i < 18; $i++) {
            foreach ($ecc as $b) {
                $code[] = $b[$i];
            }
        }
        $all = [];
        foreach ($code as $byte) {
            $this->append($all, $byte, 8);
        }$index = 0;
        for ($right = 56; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }for ($vert = 0; $vert < 57; $vert++) {
                $y = (($right + 1) & 2) === 0 ? 56 - $vert : $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if (! $this->reserved[$y][$x]) {
                        $bit = (bool) ($all[$index++] ?? 0);
                        $this->modules[$y][$x] = $bit ^ (($x + $y) % 2 === 0);
                    }
                }
            }
        }
        $paths = [];
        foreach ($this->modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $paths[] = 'M'.($x + 4).','.($y + 4).'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 65 65" role="img" aria-label="QR verifikasi Juragan Migran"><rect width="65" height="65" fill="white"/><path d="'.implode('', $paths).'" fill="black"/></svg>';
    }
}
