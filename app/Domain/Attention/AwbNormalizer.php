<?php

namespace App\Domain\Attention;

use InvalidArgumentException;

class AwbNormalizer
{
    public function normalize(string $awb): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $awb) === 1) {
            throw new InvalidArgumentException('AWB contains a control character.');
        }

        $normalized = strtoupper(trim($awb, ' '));

        if (preg_match('/\A[A-Z0-9]{8,30}\z/D', $normalized) !== 1) {
            throw new InvalidArgumentException('AWB must contain 8–30 ASCII letters or digits.');
        }

        return $normalized;
    }
}
