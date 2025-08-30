<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Category;
use App\Services\AICategorizationService;
use App\Services\CsvProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\CreditCard;

class TransactionController extends Controller
{
    protected $aiService;
    protected $csvService;

    public function __construct(AICategorizationService $aiService, CsvProcessingService $csvService)
    {
        $this->middleware('auth:sanctum');
        $this->aiService = $aiService;
        $this->csvService = $csvService;
    }

    /**
     * Listar transações do usuário
     */
    public function index(Request $request)
    {
        $query = Transaction::with(['category', 'creditCard'])
            ->where('user_id', auth()->id());

        // Filtros
        if ($request->has('month') && $request->has('year')) {
            $query->byInvoicePeriod($request->month, $request->year);
        }

        if ($request->has('credit_card_id')) {
            $query->byCreditCard($request->credit_card_id);
        }

        if ($request->has('category_id')) {
            $query->byCategory($request->category_id);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->betweenDates($request->start_date, $request->end_date);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Upload e processamento de CSV
     */
    public function uploadCSV(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
            'credit_card_id' => 'required|exists:credit_cards,id',
            'month' => 'required|string|size:2|regex:/^[0-9]{2}$/',
            'year' => 'required|string|size:4|regex:/^[0-9]{4}$/',
        ]);

        try {
            $file = $request->file('csv_file');
            $creditCardId = $request->input('credit_card_id');
            $month = $request->input('month');
            $year = $request->input('year');
            
            // Verificar se o usuário tem acesso ao cartão
            $creditCard = CreditCard::where('id', $creditCardId)
                ->where('user_id', auth()->id())
                ->first();
                
            if (!$creditCard) {
                return response()->json(['error' => 'Cartão de crédito não encontrado'], 404);
            }

            $path = $file->store('csv_uploads', 'local');
            $fullPath = Storage::path($path);

            // Processar CSV usando serviço parametrizado (com IA)
            $results = $this->csvService->processCSVFile($fullPath, $request->user()->id, $creditCardId, $month, $year);

            // Limpar arquivo após processamento
            Storage::delete($path);

            return response()->json([
                'success' => true,
                'message' => 'CSV processado com sucesso',
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Erro no upload do CSV: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar CSV: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Processar arquivo CSV
     */
    private function processCSVFile($filePath, $userId, $creditCardId = null)
    {
        $processedCount = 0;
        $errors = [];
        $duplicates = 0;

        if (($handle = fopen($filePath, 'r')) !== false) {
            // Pular cabeçalho (formato esperado: data,title,amount)
            $header = fgetcsv($handle, 1000, ',');
            
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                try {
                    // Formato esperado: data, title, amount
                    $transactionData = $this->parseCSVRow($data, $userId, $creditCardId);
                    
                    if ($transactionData) {
                        // Verificar duplicatas
                        $exists = Transaction::where('user_id', $userId)
                            ->where('transaction_date', $transactionData['transaction_date'])
                            ->where('description', $transactionData['description'])
                            ->where('amount', $transactionData['amount'])
                            ->exists();

                        if (!$exists) {
                            $transaction = Transaction::create($transactionData);
                            
                            // Classificar automaticamente com IA
                            try {
                                $aiResult = $this->aiService->categorizeTransaction($transaction);
                                if ($aiResult['category_id'] && $aiResult['confidence'] >= 0.3) {
                                    $transaction->update([
                                        'category_id' => $aiResult['category_id'],
                                        'is_categorized_by_ai' => true,
                                        'ai_confidence' => $aiResult['confidence']
                                    ]);
                                }
                            } catch (\Exception $e) {
                                Log::warning('Erro na classificação por IA: ' . $e->getMessage(), [
                                    'transaction_id' => $transaction->id
                                ]);
                            }
                            
                            $processedCount++;
                        } else {
                            $duplicates++;
                        }
                    }
                } catch (\Exception $e) {
                    $errors[] = 'Erro na linha: ' . $e->getMessage();
                }
            }
            fclose($handle);
        }

        return [
            'processed' => $processedCount,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ];
    }

    /**
     * Parsear linha do CSV
     */
    private function parseCSVRow($data, $userId, $creditCardId = null)
    {
        // Formato esperado: data, title, amount
        if (count($data) < 3) {
            return null;
        }

        try {
            // Índices para o formato: data, title, amount
            $dateString = trim($data[0]);
            $title = trim($data[1]);
            $amountString = trim($data[2]);

            // Tentar diferentes formatos de data
            $date = null;
            $dateFormats = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'];
            
            foreach ($dateFormats as $format) {
                try {
                    $date = Carbon::createFromFormat($format, $dateString);
                    break;
                } catch (\Exception $e) {
                    continue;
                }
            }
            
            if (!$date) {
                throw new \Exception("Formato de data inválido: {$dateString}");
            }

            // Processar valor monetário - aceitar vários formatos
            $rawAmount = $amountString;
            // Remover símbolos de moeda e espaços
            $rawAmount = preg_replace('/[R$\s]/', '', $rawAmount);
            // Tratar separadores decimais (vírgula ou ponto)
            if (strpos($rawAmount, ',') !== false && strpos($rawAmount, '.') !== false) {
                // Formato: 1.234,56
                $rawAmount = str_replace('.', '', $rawAmount);
                $rawAmount = str_replace(',', '.', $rawAmount);
            } elseif (strpos($rawAmount, ',') !== false) {
                // Formato: 1234,56
                $rawAmount = str_replace(',', '.', $rawAmount);
            }
            
            $amount = abs(floatval($rawAmount));
            
            if ($amount <= 0) {
                throw new \Exception("Valor inválido: {$amountString}");
            }

            // Extrair estabelecimento do título
            $establishment = $this->extractEstablishment($title);

            return [
                'user_id' => $userId,
                'credit_card_id' => $creditCardId,
                'transaction_date' => $date->format('Y-m-d'),
                'description' => $title,
                'establishment' => $establishment,
                'amount' => $amount,
                'raw_description' => $title,
                'metadata' => [
                    'original_row' => $data,
                    'imported_at' => now()->toISOString(),
                    'original_date' => $dateString,
                    'original_amount' => $amountString,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Erro ao parsear linha CSV: ' . $e->getMessage(), ['data' => $data]);
            return null;
        }
    }

    /**
     * Extrair nome do estabelecimento da descrição
     */
    private function extractEstablishment($description)
    {
        // Remover padrões comuns do Nubank
        $cleaned = preg_replace('/^(Compra no débito|Compra no crédito|PIX|TED)\s*-?\s*/i', '', $description);
        $cleaned = preg_replace('/\s*-\s*\d{2}\/\d{2}$/', '', $cleaned);
        
        // Pegar primeira parte antes de hífen ou número
        $parts = preg_split('/\s*-\s*|\s+\d/', $cleaned, 2);
        
        return trim($parts[0]);
    }

    /**
     * Obter uma transação específica
     */
    public function show($id, Request $request)
    {
        $transaction = Transaction::with(['category', 'creditCard'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'transaction' => $transaction,
        ]);
    }

    /**
     * Atualizar categoria de uma transação
     */
    public function updateCategory(Request $request, $id)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
        ]);

        $transaction = Transaction::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $transaction->update([
            'category_id' => $request->category_id,
            'is_categorized_by_ai' => false, // Marcado como categorizado manualmente
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Categoria atualizada com sucesso',
            'transaction' => $transaction->load('category'),
        ]);
    }

    /**
     * Re-classificar transações existentes com IA
     */
    public function recategorizeWithAI(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'array',
            'transaction_ids.*' => 'exists:transactions,id',
            'force' => 'boolean',
            'min_confidence' => 'numeric|min:0|max:1'
        ]);

        $userId = $request->user()->id;
        $minConfidence = $request->get('min_confidence', 0.7);
        $force = $request->get('force', false);

        $query = Transaction::where('user_id', $userId);
        
        if ($request->has('transaction_ids')) {
            $query->whereIn('id', $request->transaction_ids);
        } else {
            // Se não especificou IDs, processar apenas não categorizadas ou com baixa confiança
            if (!$force) {
                $query->where(function($q) {
                    $q->whereNull('category_id')
                      ->orWhere('ai_confidence', '<', 0.7);
                });
            }
        }

        $transactions = $query->get();
        
        if ($transactions->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhuma transação encontrada para re-classificar'
            ]);
        }

        $results = [
            'processed' => 0,
            'categorized' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => []
        ];

        foreach ($transactions as $transaction) {
            try {
                $results['processed']++;
                
                $aiResult = $this->aiService->categorizeTransaction($transaction);
                
                if ($aiResult['category_id'] && $aiResult['confidence'] >= $minConfidence) {
                    $transaction->update([
                        'category_id' => $aiResult['category_id'],
                        'is_categorized_by_ai' => true,
                        'ai_confidence' => $aiResult['confidence']
                    ]);
                    
                    $results['categorized']++;
                    $results['details'][] = [
                        'transaction_id' => $transaction->id,
                        'description' => $transaction->description,
                        'category' => $transaction->category->name,
                        'confidence' => $aiResult['confidence'],
                        'reasoning' => $aiResult['reasoning']
                    ];
                } else {
                    $results['skipped']++;
                }
                
            } catch (\Exception $e) {
                $results['errors']++;
                Log::error('Erro na re-classificação: ' . $e->getMessage(), [
                    'transaction_id' => $transaction->id
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Processamento concluído: {$results['categorized']} transações categorizadas",
            'results' => $results
        ]);
    }

    /**
     * Obter estatísticas de classificação por IA
     */
    public function getAIStats(Request $request)
    {
        $userId = $request->user()->id;

        $stats = [
            'total_transactions' => Transaction::where('user_id', $userId)->count(),
            'ai_categorized' => Transaction::where('user_id', $userId)
                ->where('is_categorized_by_ai', true)->count(),
            'manually_categorized' => Transaction::where('user_id', $userId)
                ->where('is_categorized_by_ai', false)
                ->whereNotNull('category_id')->count(),
            'uncategorized' => Transaction::where('user_id', $userId)
                ->whereNull('category_id')->count(),
            'high_confidence' => Transaction::where('user_id', $userId)
                ->where('ai_confidence', '>=', 0.8)->count(),
            'medium_confidence' => Transaction::where('user_id', $userId)
                ->whereBetween('ai_confidence', [0.6, 0.79])->count(),
            'low_confidence' => Transaction::where('user_id', $userId)
                ->whereBetween('ai_confidence', [0.0, 0.59])->count(),
        ];

        $stats['categorization_rate'] = $stats['total_transactions'] > 0 
            ? round(($stats['ai_categorized'] + $stats['manually_categorized']) / $stats['total_transactions'] * 100, 2)
            : 0;

        $stats['ai_accuracy'] = $stats['ai_categorized'] > 0 
            ? round($stats['high_confidence'] / $stats['ai_categorized'] * 100, 2)
            : 0;

        // Top categorias por IA
        $topAICategories = Transaction::with('category')
            ->where('user_id', $userId)
            ->where('is_categorized_by_ai', true)
            ->selectRaw('category_id, COUNT(*) as count, AVG(ai_confidence) as avg_confidence')
            ->groupBy('category_id')
            ->orderBy('count', 'desc')
            ->limit(5)
            ->get()
            ->map(function($item) {
                return [
                    'category' => $item->category ? $item->category->name : 'Sem categoria',
                    'count' => $item->count,
                    'avg_confidence' => round($item->avg_confidence, 2)
                ];
            });

        $stats['top_ai_categories'] = $topAICategories;

        return response()->json([
            'success' => true,
            'stats' => $stats
        ]);
    }
}
