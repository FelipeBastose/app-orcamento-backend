<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Criar usuário de teste
        User::create([
            'name' => 'Usuário Teste',
            'email' => 'teste@email.com',
            'password' => bcrypt('123456'),
        ]);

        // Criar usuário administrador
        User::create([
            'name' => 'Administrador',
            'email' => 'admin@email.com',
            'password' => bcrypt('admin123'),
        ]);

        // Criar usuário para desenvolvimento
        User::create([
            'name' => 'Desenvolvedor',
            'email' => 'dev@email.com',
            'password' => bcrypt('dev123'),
        ]);
    }
}

