<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CsvMapping;
use App\Models\CreditCard;

class CsvMappingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Buscar cartões existentes
        $nubankCard = CreditCard::where('institution', 'Nubank')->first();
        $interCard = CreditCard::where('institution', 'Inter')->first();

        if ($nubankCard) {
            // Mapeamento específico para cartão Nubank
            CsvMapping::create([
                'credit_card_id' => $nubankCard->id,
                'name' => 'Nubank Padrão',
                'institution' => 'Nubank',
                'column_mapping' => [
                    'date' => 0,        // Coluna 0: Data
                    'description' => 1,  // Coluna 1: Título/Descrição
                    'amount' => 2,       // Coluna 2: Valor
                ],
                'date_format' => ['Y-m-d'], // Formato de data ISO
                'amount_format' => [
                    'currency_symbol' => null, // Sem símbolo de moeda
                    'decimal_separator' => '.',
                    'thousands_separator' => null,
                    'remove_currency_symbol' => false,
                    'negative_values_are_income' => true, // Valores negativos são receitas
                ],
                'delimiter' => ',',
                'has_header' => true,
                'is_active' => true,
            ]);
        }

        if ($interCard) {
            // Mapeamento específico para cartão Inter (formato completo)
            CsvMapping::create([
                'credit_card_id' => $interCard->id,
                'name' => 'Inter Padrão',
                'institution' => 'Inter',
                'column_mapping' => [
                    'date' => 0,        // Coluna 0: Data
                    'description' => 1,  // Coluna 1: Lançamento
                    'category' => 2,     // Coluna 2: Categoria
                    'type' => 3,         // Coluna 3: Tipo
                    'amount' => 4,       // Coluna 4: Valor
                ],
                'date_format' => ['d/m/Y'], // Formato de data brasileiro
                'amount_format' => [
                    'currency_symbol' => 'R$',
                    'decimal_separator' => ',',
                    'thousands_separator' => '.',
                    'remove_currency_symbol' => true,
                ],
                'delimiter' => ',',
                'has_header' => true,
                'is_active' => true,
            ]);

            // Mapeamento alternativo para Inter (formato mais simples)
            CsvMapping::create([
                'credit_card_id' => $interCard->id,
                'name' => 'Inter Simples',
                'institution' => 'Inter',
                'column_mapping' => [
                    'date' => 0,        // Coluna 0: Data
                    'description' => 1,  // Coluna 1: Lançamento
                    'amount' => 2,       // Coluna 2: Valor
                ],
                'date_format' => ['d/m/Y'],
                'amount_format' => [
                    'currency_symbol' => 'R$',
                    'decimal_separator' => ',',
                    'thousands_separator' => '.',
                    'remove_currency_symbol' => true,
                ],
                'delimiter' => ',',
                'has_header' => true,
                'is_active' => true,
            ]);
        }
    }
}
