<?php

namespace App\Console\Commands;

use App\Models\AnalyticsUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

#[Signature('analytics:admin:create')]
#[Description('Crea un administrador de ESCALL Analytics')]
final class CreateAnalyticsAdministrator extends Command
{
    public function handle(): int
    {
        $name = (string) $this->ask('Nombre');
        $email = mb_strtolower((string) $this->ask('Correo'));
        $password = (string) $this->secret('Contraseña');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:analytics_users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        AnalyticsUser::query()->create([
            'name' => trim($name),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => AnalyticsUser::ROLE_ADMINISTRADOR,
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        $this->info('Administrador creado.');

        return self::SUCCESS;
    }
}
