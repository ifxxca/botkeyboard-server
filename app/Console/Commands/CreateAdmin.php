<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('app:create-admin {email} {--name=Admin}')]
#[Description('Create one admin account; password is prompted without echo')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) $this->option('name');

        if (Validator::make(compact('email', 'name'), [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ])->fails() || User::where('email', $email)->exists()) {
            $this->error('Invalid or existing admin identity.');

            return self::FAILURE;
        }

        $password = $this->secret('Admin password (minimum 12 characters)');

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Password must contain at least 12 characters.');

            return self::FAILURE;
        }

        User::create(['email' => $email, 'name' => $name, 'password' => $password]);
        $this->info('Admin account created.');

        return self::SUCCESS;
    }
}
