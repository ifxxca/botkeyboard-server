<?php

namespace App\Services;

class ClientTokenService
{
    public function generate(): string
    {
        return 'aim_'.bin2hex(random_bytes(32));
    }

    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
