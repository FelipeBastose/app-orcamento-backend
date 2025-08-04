<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use OpenAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AICategorizationService
{
    private $categories;
    private $categoryExamples;

    public function __construct()
    {
        $this->categories = Category::all();
        $this->categoryExamples = $this->getCategoryExamples();
    }

    /**
     * Classificar uma transação usando IA
     */
    public function categorizeTransaction(Transaction $transaction)
    {
        try {
            // Verificar se a classificação já está em cache
            $cacheKey = 'ai_category_' . md5($transaction->description . $transaction->establishment);
            $cached = Cache::get($cacheKey);
            
            if ($cached) {
                return $cached;
            }

            // Preparar prompt para IA
            $prompt = $this->buildPrompt($transaction);
            
            // Chamada para OpenAI
            $apiKey = env('OPENAI_API_KEY');
            if (empty($apiKey)) {
                // Simulação sem IA real
                return $this->getFallbackCategorization($transaction);
            }
            
            $client = OpenAI::client($apiKey);
            $response = $client->chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => 150,
                'temperature' => 0.3,
            ]);

            $result = $this->parseAIResponse($response->choices[0]->message->content);
            
            // Cache por 30 dias
            Cache::put($cacheKey, $result, now()->addDays(30));
            
            return $result;

        } catch (\Exception $e) {
            Log::error('Erro na classificação por IA: ' . $e->getMessage(), [
                'transaction_id' => $transaction->id,
                'description' => $transaction->description
            ]);
            
            // Fallback: classificar com base em palavras-chave
            return $this->fallbackCategorization($transaction);
        }
    }

    /**
     * Classificar múltiplas transações
     */
    public function categorizeMultipleTransactions($transactions)
    {
        $results = [];
        
        foreach ($transactions as $transaction) {
            $result = $this->categorizeTransaction($transaction);
            
            if ($result['category_id']) {
                $transaction->update([
                    'category_id' => $result['category_id'],
                    'is_categorized_by_ai' => true,
                    'ai_confidence' => $result['confidence']
                ]);
            }
            
            $results[] = [
                'transaction_id' => $transaction->id,
                'category_id' => $result['category_id'],
                'confidence' => $result['confidence'],
                'reasoning' => $result['reasoning']
            ];
        }
        
        return $results;
    }

    /**
     * Sistema prompt para IA
     */
    private function getSystemPrompt()
    {
        $categoriesText = $this->categories->map(function($category) {
            return "- {$category->name}: {$category->description}";
        })->join("\n");

        return "Você é um especialista em categorização de gastos financeiros.

Categorias disponíveis:
{$categoriesText}

Sua tarefa é analisar transações de cartão de crédito e classificá-las na categoria mais apropriada.

Responda SEMPRE no formato JSON:
{
    \"category_name\": \"nome_da_categoria\",
    \"confidence\": 0.95,
    \"reasoning\": \"explicação_breve\"
}

Regras:
- confidence deve ser um número entre 0.0 e 1.0
- Se não tiver certeza (confidence < 0.7), use \"Outros\"
- Seja preciso e considere o contexto brasileiro
- Analise tanto a descrição quanto o estabelecimento";
    }

    /**
     * Construir prompt específico para transação
     */
    private function buildPrompt(Transaction $transaction)
    {
        $examples = $this->getRelevantExamples($transaction);
        
        return "Analise esta transação e classifique na categoria mais apropriada:

Descrição: {$transaction->description}
Estabelecimento: {$transaction->establishment}
Valor: R$ {$transaction->amount}
Data: {$transaction->transaction_date->format('d/m/Y')}

Exemplos de classificações similares:
{$examples}

Classifique esta transação:";
    }

    /**
     * Obter exemplos relevantes para contexto
     */
    private function getRelevantExamples(Transaction $transaction)
    {
        $examples = [];
        
        foreach ($this->categoryExamples as $category => $categoryExamples) {
            $examples[] = "**{$category}:**";
            foreach (array_slice($categoryExamples, 0, 2) as $example) {
                $examples[] = "- {$example}";
            }
        }
        
        return implode("\n", $examples);
    }

    /**
     * Parsear resposta da IA
     */
    private function parseAIResponse($response)
    {
        try {
            // Limpar resposta
            $cleanResponse = preg_replace('/```json\s*/', '', $response);
            $cleanResponse = preg_replace('/```\s*$/', '', $cleanResponse);
            $cleanResponse = trim($cleanResponse);
            
            $data = json_decode($cleanResponse, true);
            
            if (!$data || !isset($data['category_name'])) {
                throw new \Exception('Resposta inválida da IA');
            }
            
            // Encontrar categoria pelo nome
            $category = $this->categories->firstWhere('name', $data['category_name']);
            
            return [
                'category_id' => $category ? $category->id : null,
                'confidence' => $data['confidence'] ?? 0.5,
                'reasoning' => $data['reasoning'] ?? 'Classificação automática'
            ];
            
        } catch (\Exception $e) {
            Log::warning('Erro ao parsear resposta da IA: ' . $e->getMessage(), ['response' => $response]);
            
            return [
                'category_id' => null,
                'confidence' => 0.0,
                'reasoning' => 'Erro na classificação'
            ];
        }
    }

    /**
     * Classificação fallback baseada em palavras-chave
     */
    private function fallbackCategorization(Transaction $transaction)
    {
        $text = strtolower($transaction->description . ' ' . $transaction->establishment);
        
        $keywordMap = [
            'Alimentação' => ['supermercado', 'mercado', 'restaurante', 'lanchonete', 'padaria', 'açougue', 'delivery', 'ifood', 'uber eats', 'rappi'],
            'Transporte' => ['uber', 'cabify', '99', 'posto', 'combustivel', 'gasolina', 'pedagio', 'metro', 'onibus'],
            'Saúde' => ['farmacia', 'drogaria', 'hospital', 'clinica', 'medico', 'dentista', 'laboratorio'],
            'Lazer' => ['cinema', 'shopping', 'parque', 'show', 'teatro', 'netflix', 'spotify', 'amazon prime'],
            'Compras Online' => ['mercado livre', 'amazon', 'magazine luiza', 'americanas', 'shopee', 'aliexpress'],
            'Vestuário' => ['loja', 'roupa', 'calcado', 'sapato', 'tenis', 'zara', 'c&a', 'riachuelo'],
            'Casa & Utilidades' => ['casa', 'móveis', 'decoração', 'limpeza', 'casa bahia', 'leroy merlin'],
            'Serviços' => ['streaming', 'assinatura', 'netflix', 'spotify', 'google', 'microsoft'],
        ];
        
        foreach ($keywordMap as $categoryName => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $category = $this->categories->firstWhere('name', $categoryName);
                    return [
                        'category_id' => $category ? $category->id : null,
                        'confidence' => 0.6,
                        'reasoning' => "Palavra-chave detectada: {$keyword}"
                    ];
                }
            }
        }
        
        // Se não encontrou nada, retorna "Outros"
        $category = $this->categories->firstWhere('name', 'Outros');
        return [
            'category_id' => $category ? $category->id : null,
            'confidence' => 0.3,
            'reasoning' => 'Classificação padrão - não identificado'
        ];
    }

    /**
     * Exemplos de categoria para treinar contexto
     */
    private function getCategoryExamples()
    {
        return [
            'Alimentação' => [
                'Supermercado Extra - São Paulo',
                'McDonald\'s - Delivery',
                'Padaria do João - Centro',
                'iFood - Restaurante Italiano'
            ],
            'Transporte' => [
                'Uber - Corrida',
                'Posto Shell - Gasolina',
                'Pedágio AutoBAn',
                '99 Pop - Viagem'
            ],
            'Saúde' => [
                'Drogaria São Paulo',
                'Clínica Médica Dr. Silva',
                'Laboratório Fleury',
                'Farmácia Pague Menos'
            ],
            'Lazer' => [
                'Cinemark Shopping',
                'Netflix Assinatura',
                'Spotify Premium',
                'Parque Ibirapuera'
            ],
            'Compras Online' => [
                'Mercado Livre - Eletrônicos',
                'Amazon Brasil',
                'Magazine Luiza',
                'Shopee Brasil'
            ],
            'Vestuário' => [
                'Zara - Shopping Center',
                'Nike Store',
                'Riachuelo Moda',
                'C&A Departamento'
            ],
            'Casa & Utilidades' => [
                'Leroy Merlin',
                'Casa Bahia Móveis',
                'Tok&Stok Decoração',
                'Supermercado Limpeza'
            ],
            'Serviços' => [
                'Google Drive Storage',
                'Microsoft Office 365',
                'Adobe Creative Cloud',
                'Dropbox Premium'
            ],
            'Educação' => [
                'Udemy Curso Online',
                'Universidade Mensalidade',
                'Livros Amazon',
                'Coursera Assinatura'
            ]
        ];
    }

    /**
     * Treinar modelo com transações existentes
     */
    public function trainWithExistingData()
    {
        $categorizedTransactions = Transaction::whereNotNull('category_id')
            ->with('category')
            ->limit(100)
            ->get();
            
        $trainingData = [];
        
        foreach ($categorizedTransactions as $transaction) {
            $trainingData[] = [
                'description' => $transaction->description,
                'establishment' => $transaction->establishment,
                'category' => $transaction->category->name,
                'amount' => $transaction->amount
            ];
        }
        
        // Cache dados de treinamento
        Cache::put('ai_training_data', $trainingData, now()->addDays(7));
        
        return $trainingData;
    }

    /**
     * Categorização de fallback quando OpenAI não está disponível
     */
    private function getFallbackCategorization(Transaction $transaction)
    {
        $description = strtolower($transaction->description);
        $establishment = strtolower($transaction->establishment ?? '');
        
        // Categorização baseada em palavras-chave
        $keywords = [
            'Alimentação' => ['mercado', 'supermercado', 'padaria', 'restaurante', 'lanchonete', 'delivery', 'ifood', 'uber eats'],
            'Transporte' => ['posto', 'gasolina', 'combustivel', 'uber', 'taxi', 'metro', 'onibus'],
            'Saúde' => ['farmacia', 'drogaria', 'hospital', 'clinica', 'dentista', 'medico'],
            'Lazer' => ['cinema', 'teatro', 'netflix', 'spotify', 'amazon prime'],
            'Shopping' => ['shopping', 'magazine', 'loja', 'americanas', 'mercado livre'],
            'Casa' => ['casa', 'construção', 'eletrico', 'agua', 'luz'],
            'Educação' => ['escola', 'faculdade', 'curso', 'livro'],
            'Outros' => []
        ];
        
        foreach ($keywords as $categoryName => $keywordList) {
            foreach ($keywordList as $keyword) {
                if (strpos($description, $keyword) !== false || strpos($establishment, $keyword) !== false) {
                    $category = Category::where('name', $categoryName)->first();
                    if ($category) {
                        return [
                            'category_id' => $category->id,
                            'confidence' => 0.6, // Confiança média para categorização automática
                            'method' => 'keyword_matching'
                        ];
                    }
                }
            }
        }
        
        // Se não encontrou categoria específica, usar "Outros"
        $defaultCategory = Category::where('name', 'Outros')->first();
        return [
            'category_id' => $defaultCategory ? $defaultCategory->id : null,
            'confidence' => 0.3,
            'method' => 'default_fallback'
        ];
    }
}