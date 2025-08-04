<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Alimentação',
                'description' => 'Gastos com supermercados, restaurantes e delivery',
                'color' => '#ff6b6b',
                'icon' => 'utensils',
                'is_default' => true
            ],
            [
                'name' => 'Transporte',
                'description' => 'Uber, combustível, pedágios e transporte público',
                'color' => '#4ecdc4',
                'icon' => 'car',
                'is_default' => true
            ],
            [
                'name' => 'Saúde',
                'description' => 'Farmácias, consultas médicas e planos de saúde',
                'color' => '#45b7d1',
                'icon' => 'heart',
                'is_default' => true
            ],
            [
                'name' => 'Lazer',
                'description' => 'Cinema, shows, viagens e entretenimento',
                'color' => '#f9ca24',
                'icon' => 'gamepad',
                'is_default' => true
            ],
            [
                'name' => 'Compras Online',
                'description' => 'E-commerce, apps de compra e marketplaces',
                'color' => '#6c5ce7',
                'icon' => 'shopping-cart',
                'is_default' => true
            ],
            [
                'name' => 'Educação',
                'description' => 'Cursos, livros, materiais educacionais',
                'color' => '#a29bfe',
                'icon' => 'book',
                'is_default' => true
            ],
            [
                'name' => 'Casa & Utilidades',
                'description' => 'Móveis, decoração, produtos de limpeza',
                'color' => '#fd79a8',
                'icon' => 'home',
                'is_default' => true
            ],
            [
                'name' => 'Vestuário',
                'description' => 'Roupas, calçados e acessórios',
                'color' => '#00b894',
                'icon' => 'tshirt',
                'is_default' => true
            ],
            [
                'name' => 'Serviços',
                'description' => 'Streaming, assinaturas e serviços diversos',
                'color' => '#e17055',
                'icon' => 'cog',
                'is_default' => true
            ],
            [
                'name' => 'Outros',
                'description' => 'Gastos não categorizados',
                'color' => '#74b9ff',
                'icon' => 'question',
                'is_default' => true
            ]
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['name' => $category['name']],
                $category
            );
        }
    }
}
