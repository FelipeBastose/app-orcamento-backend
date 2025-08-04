<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use GeminiAPI\Client;
use GeminiAPI\Resources\Parts\TextPart;
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
            
            // Chamada para Gemini
            $apiKey = env('GEMINI_API_KEY');
            if (empty($apiKey)) {
                // Simulação sem IA real
                return $this->getFallbackCategorization($transaction);
            }
            
            $client = new Client($apiKey);
            $fullPrompt = $this->getSystemPrompt() . "\n\n" . $prompt;
            $response = $client->generativeModel('gemini-1.5-flash')->generateContent(
                new TextPart($fullPrompt)
            );

            $result = $this->parseAIResponse($response->text());
            
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

        return "Você é um especialista em categorização de gastos financeiros brasileiros.

Categorias disponíveis:
{$categoriesText}

ESTABELECIMENTOS ÓBVIOS (alta confiança 0.9+):
- AUTO POSTO, POSTO, SHELL, PETROBRAS, IPIRANGA = Transporte
- CARREFOUR, EXTRA, WALMART, ATACADÃO, MERCADO, SUPERMERCADO = Alimentação  
- FARMACODE, DROGASIL, PACHECO, FARMÁCIA, DROGARIA = Saúde
- SUSHI BAR, RESTAURANTE, LANCHONETE, PIZZARIA = Alimentação
- CINEMA, CINEMARK, NETFLIX, SPOTIFY = Lazer
- UBER, 99, TAXI = Transporte
- ZARA, C&A, RIACHUELO = Vestuário

Responda SEMPRE no formato JSON:
{
    \"category_name\": \"nome_da_categoria\",
    \"confidence\": 0.95,
    \"reasoning\": \"explicação_breve\"
}

Regras:
- Para estabelecimentos óbvios, use confidence 0.9 ou maior
- confidence deve ser um número entre 0.0 e 1.0
- Se não tiver certeza (confidence < 0.7), use \"Outros\"
- Seja preciso e considere o contexto brasileiro
- Analise tanto a descrição quanto o estabelecimento
- Priorize o nome do estabelecimento sobre a descrição da transação";
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
            'Alimentação' => [
                // Supermercados principais
                'supermercado', 'mercado', 'carrefour', 'extra', 'pao de acucar', 'walmart', 'big', 'atacadao', 'sam', 'assai', 'makro', 'tenda', 'guanabara', 'mondial',
                // Mercearias e pequenos mercados
                'mercearia', 'emporio', 'hortifruti', 'frutaria', 'quitanda', 'mercadinho', 'minimercado',
                // Açougues e similares
                'acougue', 'peixaria', 'avicola', 'casa de carnes',
                // Padarias
                'padaria', 'panificadora', 'confeitaria', 'bakery',
                // Restaurantes e lanchonetes
                'restaurante', 'lanchonete', 'lancheria', 'pizzaria', 'hamburgueria', 'sorveteria', 'doceria', 'cafeteria', 'cafe', 'bistrô', 'bistro',
                // Comida específica
                'sushi', 'sushi bar', 'japonesa', 'pizza', 'hamburguer', 'burger', 'pastel', 'tapioca', 'açai', 'milk shake',
                // Fast food
                'mcdonald', 'burger king', 'subway', 'kfc', 'pizza hut', 'dominos', 'bobs', 'giraffas', 'habib',
                // Delivery
                'delivery', 'ifood', 'uber eats', 'rappi', 'zé delivery', 'james delivery'
            ],
            'Transporte' => [
                // Postos de combustível
                'posto', 'auto posto', 'combustivel', 'gasolina', 'etanol', 'diesel', 'shell', 'petrobras', 'ipiranga', 'br', 'alesat', 'esso', 'texaco', 'raizen',
                // Transporte público
                'metro', 'onibus', 'pst', 'viacao', 'rodoviaria', 'terminal', 'bilhete unico', 'cartao transporte',
                // Apps de transporte
                'uber', 'cabify', '99', 'taxi', 'moto taxi', 'bla bla car',
                // Outros transportes
                'pedagio', 'estacionamento', 'valet', 'zona azul', 'rotativo', 'oficina', 'auto center', 'pneu', 'oleo'
            ],
            'Saúde' => [
                // Farmácias principais
                'farmacia', 'drogaria', 'farmacode', 'drogasil', 'pacheco', 'pague menos', 'ultrafarma', 'droga raia', 'sao joao', 'nissei', 'venancio', 'popular', 'indiana',
                // Serviços médicos
                'hospital', 'clinica', 'medico', 'dentista', 'odontologo', 'oftalmologista', 'cardiologista', 'dermatologista', 'psicólogo', 'fisioterapeuta',
                // Laboratórios e exames
                'laboratorio', 'fleury', 'dasa', 'sabin', 'hermes pardini', 'exame', 'raio x', 'ultrassom', 'ressonancia',
                // Planos de saúde
                'unimed', 'hapvida', 'sulamerica', 'bradesco saude', 'amil', 'golden cross', 'prevent senior'
            ],
            'Lazer' => [
                // Cinema e entretenimento
                'cinema', 'cinemark', 'uci', 'movie', 'ingresso', 'teatro', 'show', 'espetaculo', 'concerto',
                // Streaming e assinaturas digitais
                'netflix', 'spotify', 'amazon prime', 'disney', 'globoplay', 'paramount', 'hbo', 'apple tv', 'youtube premium', 'deezer',
                // Bares e vida noturna
                'bar', 'pub', 'balada', 'festa', 'clube', 'boteco', 'choperia', 'cervejaria',
                // Parques e lazer
                'parque', 'shopping', 'playland', 'game', 'boliche', 'sinuca', 'bilhar', 'karaoke'
            ],
            'Compras Online' => [
                'mercado livre', 'ml', 'amazon', 'magazine luiza', 'magalu', 'americanas', 'shopee', 'aliexpress', 'netshoes', 'submarino', 'extra.com', 'casas bahia.com', 'pontofrio.com', 'walmart.com', 'carrefour.com'
            ],
            'Vestuário' => [
                // Lojas de roupas
                'zara', 'c&a', 'riachuelo', 'renner', 'marisa', 'leader', 'cea', 'youcom', 'forever 21', 'hering', 'malwee',
                // Calçados
                'nike', 'adidas', 'puma', 'havaianas', 'melissa', 'grendene', 'olympikus', 'mizuno', 'arezzo', 'schutz',
                // Genérico
                'roupa', 'calcado', 'sapato', 'tenis', 'sandalia', 'bota', 'chinelo', 'moda', 'boutique'
            ],
            'Casa & Utilidades' => [
                // Construção e materiais
                'leroy merlin', 'telhanorte', 'dicico', 'construcao', 'material', 'tinta', 'eletrico', 'hidraulica', 'ferragem', 'parafuso',
                // Móveis e decoração
                'tok stok', 'etna', 'casa bahia', 'ponto frio', 'fast shop', 'mobly', 'madeira madeira', 'moveis', 'decoracao', 'estofado',
                // Limpeza e casa
                'limpeza', 'detergente', 'sabao', 'amaciante', 'desinfetante', 'utilidades', 'bazar', 'armarinho'
            ],
            'Serviços' => [
                // Assinaturas digitais
                'google', 'microsoft', 'office 365', 'adobe', 'dropbox', 'icloud', 'onedrive',
                // Correios e envios
                'correios', 'sedex', 'pac', 'envio', 'frete',
                // Serviços profissionais
                'cartorio', 'despachante', 'advogado', 'contador', 'contabilidade', 'juridico',
                // Serviços pessoais
                'barbeiro', 'cabeleireiro', 'salao', 'estetica', 'manicure', 'pedicure', 'massagem', 'spa'
            ],
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
        
        // Categorização baseada em palavras-chave expandida
        $keywords = [
            'Alimentação' => [
                'mercado', 'supermercado', 'carrefour', 'extra', 'walmart', 'atacadao', 'mercearia', 'emporio', 'hortifruti',
                'padaria', 'restaurante', 'lanchonete', 'sushi', 'sushi bar', 'pizzaria', 'hamburgueria', 'mcdonald', 'burger king',
                'delivery', 'ifood', 'uber eats', 'rappi'
            ],
            'Transporte' => [
                'posto', 'auto posto', 'gasolina', 'combustivel', 'shell', 'petrobras', 'ipiranga', 'br', 'alesat',
                'uber', 'taxi', '99', 'cabify', 'metro', 'onibus', 'pedagio', 'estacionamento'
            ],
            'Saúde' => [
                'farmacia', 'drogaria', 'farmacode', 'drogasil', 'pacheco', 'pague menos', 'ultrafarma', 'droga raia',
                'hospital', 'clinica', 'dentista', 'medico', 'laboratorio', 'unimed', 'hapvida'
            ],
            'Lazer' => [
                'cinema', 'cinemark', 'teatro', 'netflix', 'spotify', 'amazon prime', 'disney', 'bar', 'balada', 'show'
            ],
            'Compras Online' => [
                'mercado livre', 'ml', 'amazon', 'magazine luiza', 'magalu', 'americanas', 'shopee', 'aliexpress'
            ],
            'Vestuário' => [
                'zara', 'c&a', 'riachuelo', 'renner', 'nike', 'adidas', 'roupa', 'calcado', 'sapato', 'tenis'
            ],
            'Casa & Utilidades' => [
                'leroy merlin', 'construção', 'eletrico', 'casa bahia', 'tok stok', 'moveis', 'decoracao', 'limpeza'
            ],
            'Serviços' => [
                'google', 'microsoft', 'correios', 'cartorio', 'barbeiro', 'cabeleireiro', 'estetica'
            ],
            'Educação' => ['escola', 'faculdade', 'curso', 'livro', 'udemy', 'coursera'],
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