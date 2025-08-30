<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CreditCard;
use App\Models\User;

class CreditCardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Buscar todos os usuários
        $users = User::all();
        
        if ($users->isEmpty()) {
            // Se não houver usuários, criar um
            $user = User::create([
                'name' => 'Usuário Exemplo',
                'email' => 'exemplo@email.com',
                'password' => bcrypt('password'),
            ]);
            $users = collect([$user]);
        }

        // Criar cartões para cada usuário
        foreach ($users as $user) {
            // Cartão Nubank
            CreditCard::create([
                'user_id' => $user->id,
                'name' => 'Cartão Nubank',
                'institution' => 'Nubank',
                'brand' => 'Mastercard',
                'last_digits' => '1234',
                'color' => '#8A05BE',
                'is_active' => true,
            ]);

            // Cartão Inter
            CreditCard::create([
                'user_id' => $user->id,
                'name' => 'Cartão Inter',
                'institution' => 'Inter',
                'brand' => 'Visa',
                'last_digits' => '5678',
                'color' => '#FF7A00',
                'is_active' => true,
            ]);
        }
    }
}
