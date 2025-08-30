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
     * Listar transações com filtros
     */
    public function index(Request $request)
    {
        $query = Transaction::with(['category', 'creditCard'])
            ->where('user_id', $request->user()->id);

        // Filtro por categoria
        if ($request->has('category_id') && $request->category_id) {
            $query->where('category_id', $request->category_id);
        }

        // Filtro por data
        if ($request->has('start_date') && $request->start_date) {
            $query->where('transaction_date', '>=', $request->start_date);
        }

        if ($request->has('end_date') && $request->end_date) {
            $query->where('transaction_date', '<=', $request->end_date);
        }

        // Filtro por valor mínimo
        if ($request->has('min_amount') && $request->min_amount) {
            $query->where('amount', '>=', $request->min_amount);
        }

        // Filtro por valor máximo
        if ($request->has('max_amount') && $request->max_amount) {
            $query->where('amount', '<=', $request->max_amount);
        }

        // Filtro por estabelecimento
        if ($request->has('establishment') && $request->establishment) {
            $query->where('establishment', 'like', '%' . $request->establishment . '%');
        }

        // Filtro por cartão de crédito
        if ($request->has('credit_card_id') && $request->credit_card_id) {
            $query->where('credit_card_id', $request->credit_card_id);
        }

        // Ordenação
        $sortBy = $request->get('sort_by', 'transaction_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $transactions = $query->paginate($request->get('per_page', 15));

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
            'credit_card_id' => 'nullable|exists:credit_cards,id',
        ]);

        try {
            $file = $request->file('csv_file');
            $path = $file->store('csv_uploads', 'local');
            $fullPath = Storage::path($path);

            // Processar CSV usando serviço parametrizado (com IA)
            $results = $this->csvService->processCSVFile($fullPath, $request->user()->id, $request->credit_card_id);

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
     * Criar nova transação
     */
    public function store(Request $request)
    {
        $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'transaction_date' => 'required|date',
            'category_id' => 'nullable|exists:categories,id',
            'credit_card_id' => 'nullable|exists:credit_cards,id',
            'establishment' => 'nullable|string|max:255',
        ]);

        $transaction = Transaction::create([
            'user_id' => $request->user()->id,
            'description' => $request->description,
            'amount' => $request->amount,
            'transaction_date' => $request->transaction_date,
            'category_id' => $request->category_id,
            'credit_card_id' => $request->credit_card_id,
            'establishment' => $request->establishment,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transação criada com sucesso',
            'transaction' => $transaction->load(['category', 'creditCard']),
        ], 201);
    }

    /**
     * Atualizar transação
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'description' => 'sometimes|string|max:255',
            'amount' => 'sometimes|numeric|min:0.01',
            'transaction_date' => 'sometimes|date',
            'category_id' => 'sometimes|exists:categories,id',
            'credit_card_id' => 'sometimes|exists:credit_cards,id',
            'establishment' => 'sometimes|string|max:255',
        ]);

        $transaction = Transaction::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $transaction->update($request->only([
            'description', 'amount', 'transaction_date', 'category_id', 'credit_card_id', 'establishment'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Transação atualizada com sucesso',
            'transaction' => $transaction->load(['category', 'creditCard']),
        ]);
    }

    /**
     * Excluir transação
     */
    public function destroy($id, Request $request)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $transaction->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transação excluída com sucesso',
        ]);
    }

    /**
     * Obter estatísticas das transações
     */
    public function getStats(Request $request)
    {
        $userId = $request->user()->id;
        $startDate = $request->get('start_date', now()->startOfMonth());
        $endDate = $request->get('end_date', now()->endOfMonth());

        $stats = Transaction::where('user_id', $userId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as total_transactions,
                SUM(amount) as total_amount,
                AVG(amount) as average_amount,
                MIN(amount) as min_amount,
                MAX(amount) as max_amount
            ')
            ->first();

        // Estatísticas por categoria
        $categoryStats = Transaction::where('user_id', $userId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->whereNotNull('category_id')
            ->with('category')
            ->selectRaw('category_id, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderBy('total', 'desc')
            ->get();

        // Estatísticas por cartão
        $cardStats = Transaction::where('user_id', $userId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->whereNotNull('credit_card_id')
            ->with('creditCard')
            ->selectRaw('credit_card_id, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('credit_card_id')
            ->orderBy('total', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'category_stats' => $categoryStats,
            'card_stats' => $cardStats,
        ]);
    }

    /**
     * Buscar transações por texto
     */
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:2',
        ]);

        $query = Transaction::with(['category', 'creditCard'])
            ->where('user_id', $request->user()->id)
            ->where(function ($q) use ($request) {
                $q->where('description', 'like', '%' . $request->query . '%')
                  ->orWhere('establishment', 'like', '%' . $request->query . '%')
                  ->orWhere('raw_description', 'like', '%' . $request->query . '%');
            });

        $transactions = $query->orderBy('transaction_date', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'transactions' => $transactions,
        ]);
    }
}
