<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('usuarios:crear {--name= : Nombre completo} {--email= : Correo con el que iniciará sesión}')]
#[Description('Crea un usuario pidiendo la contraseña de forma interactiva, sin que quede en el historial.')]
class CrearUsuario extends Command
{
    /**
     * El sistema no tiene registro público: el primer usuario de una instalación
     * tiene que crearse desde la consola, y los siguientes también.
     *
     * La contraseña se pide con `secret()` y nunca se acepta como argumento. Un
     * `--password=` quedaría en el historial de la shell, en la lista de procesos
     * del servidor y —si el comando se corre por SSH— también en el historial de
     * la máquina de desarrollo. Tres sitios donde una contraseña de producción no
     * tiene por qué estar.
     */
    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Nombre completo');
        $email = $this->option('email') ?: $this->ask('Correo electrónico');

        $password = $this->secret('Contraseña');
        $confirmacion = $this->secret('Repite la contraseña');

        if ($password !== $confirmacion) {
            $this->error('Las contraseñas no coinciden.');

            return self::FAILURE;
        }

        $validador = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // El cast 'hashed' del modelo se encarga del hash; asignar aquí un
        // Hash::make() lo cifraría dos veces y la contraseña no funcionaría.
        $usuario = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Usuario creado: {$usuario->email}");

        return self::SUCCESS;
    }
}
