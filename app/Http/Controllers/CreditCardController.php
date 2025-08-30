<?php

namespace App\Http\Controllers;

use App\Models\CreditCard;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditCardController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Listar cartões de crédito do usuário
     */
    public function index(Request $request)
    {
        $creditCards = CreditCard::where('user_id', $request->user()->id)
            ->withCount('transactions')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'credit_cards' => $creditCards,
        ]);
    }

    /**
     * Criar novo cartão de crédito
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'institution' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'last_digits' => 'nullable|string|max:4',
            'color' => 'nullable|string|max:7',
        ]);

        $creditCard = CreditCard::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'institution' => $request->institution,
            'brand' => $request->brand,
            'last_digits' => $request->last_digits,
            'color' => $request->color ?? '#3B82F6',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cartão de crédito criado com sucesso',
            'credit_card' => $creditCard,
        ], 201);
    }

    /**
     * Atualizar cartão de crédito
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'institution' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'last_digits' => 'nullable|string|max:4',
            'color' => 'nullable|string|max:7',
            'is_active' => 'boolean',
        ]);

        $creditCard = CreditCard::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $creditCard->update($request->only([
            'name', 'institution', 'brand', 'last_digits', 'color', 'is_active'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Cartão de crédito atualizado com sucesso',
            'credit_card' => $creditCard,
        ]);
    }

    /**
     * Excluir cartão de crédito
     */
    public function destroy($id, Request $request)
    {
        $creditCard = CreditCard::where('user_id', $request->user()->id)
            ->findOrFail($id);

        // Verificar se há transações associadas
        $transactionCount = Transaction::where('credit_card_id', $id)->count();
        
        if ($transactionCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível excluir o cartão. Existem ' . $transactionCount . ' transações associadas.',
            ], 400);
        }

        $creditCard->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cartão de crédito excluído com sucesso',
        ]);
    }

    /**
     * Obter estatísticas por cartão de crédito
     */
    public function statistics(Request $request)
    {
        $userId = $request->user()->id;
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $query = Transaction::select(
            'credit_cards.id',
            'credit_cards.name',
            'credit_cards.institution',
            'credit_cards.brand',
            'credit_cards.color',
            DB::raw('COUNT(*) as transaction_count'),
            DB::raw('SUM(amount) as total_amount'),
            DB::raw('AVG(amount) as average_amount'),
            DB::raw('MIN(amount) as min_amount'),
            DB::raw('MAX(amount) as max_amount')
        )
        ->join('credit_cards', 'transactions.credit_card_id', '=', 'credit_cards.id')
        ->where('transactions.user_id', $userId)
        ->whereNotNull('transactions.credit_card_id');

        if ($startDate) {
            $query->where('transactions.transaction_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('transactions.transaction_date', '<=', $endDate);
        }

        $statistics = $query->groupBy(
            'credit_cards.id',
            'credit_cards.name',
            'credit_cards.institution',
            'credit_cards.brand',
            'credit_cards.color'
        )
        ->orderBy('total_amount', 'desc')
        ->get();

        return response()->json([
            'success' => true,
            'statistics' => $statistics,
        ]);
    }

    /**
     * Obter transações por cartão de crédito
     */
    public function transactions($id, Request $request)
    {
        $creditCard = CreditCard::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $query = Transaction::with('category')
            ->where('user_id', $request->user()->id)
            ->where('credit_card_id', $id);

        // Aplicar filtros
        if ($request->has('start_date') && $request->start_date) {
            $query->where('transaction_date', '>=', $request->start_date);
        }

        if ($request->has('end_date') && $request->end_date) {
            $query->where('transaction_date', '<=', $request->end_date);
        }

        if ($request->has('category_id') && $request->category_id) {
            $query->where('category_id', $request->category_id);
        }

        // Ordenação
        $sortBy = $request->get('sort_by', 'transaction_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $transactions = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'credit_card' => $creditCard,
            'transactions' => $transactions,
        ]);
    }
}



