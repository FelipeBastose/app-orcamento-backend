<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Category;
use App\Models\CreditCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Obter dados do dashboard
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $currentMonth = now()->month;
        $currentYear = now()->year;

        return response()->json([
            'success' => true,
            'dashboard' => [
                'current_month_summary' => $this->getCurrentMonthSummary($userId),
                'expenses_by_category' => $this->getExpensesByCategory($userId, $currentMonth, $currentYear),
                'expenses_by_credit_card' => $this->getExpensesByCreditCard($userId, $currentMonth, $currentYear),
                'expenses_by_day' => $this->getExpensesByDay($userId, $currentMonth, $currentYear),
                'top_establishments' => $this->getTopEstablishments($userId, $currentMonth, $currentYear),
                'insights' => $this->getInsights($userId, $currentMonth, $currentYear),
                'monthly_comparison' => $this->getMonthlyComparison($userId),
                'monthly_totals' => $this->getMonthlyTotals($userId),
                'available_months' => $this->getAvailableMonths($userId)
            ]
        ]);
    }

    /**
     * Resumo dos dados
     */
    private function getCurrentMonthSummary($userId)
    {
        $summary = Transaction::where('user_id', $userId)
            ->selectRaw('
                COUNT(*) as total_transactions,
                SUM(amount) as total_amount,
                AVG(amount) as average_amount,
                MAX(amount) as highest_amount,
                MIN(amount) as lowest_amount
            ')
            ->first();

        $dailyAverage = ($summary->total_amount ?? 0) / 30;
        $monthProjection = $summary->total_amount ?? 0;

        return [
            'total_transactions' => $summary->total_transactions ?? 0,
            'total_amount' => round($summary->total_amount ?? 0, 2),
            'average_amount' => round($summary->average_amount ?? 0, 2),
            'highest_amount' => round($summary->highest_amount ?? 0, 2),
            'lowest_amount' => round($summary->lowest_amount ?? 0, 2),
            'daily_average' => round($dailyAverage, 2),
            'month_projection' => round($monthProjection, 2),
            'days_in_month' => now()->daysInMonth,
            'current_day' => now()->day,
        ];
    }

    /**
     * Gastos por categoria (para gráfico de pizza)
     */
    private function getExpensesByCategory($userId, $month, $year)
    {
        return Transaction::with('category')
            ->where('user_id', $userId)
            ->selectRaw('category_id, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category_id')
            ->orderBy('total', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'category_id' => $item->category_id,
                    'category_name' => $item->category ? $item->category->name : 'Sem categoria',
                    'category_color' => $item->category ? $item->category->color : '#cccccc',
                    'total' => round($item->total, 2),
                    'count' => $item->count,
                    'percentage' => 0, // Será calculado no frontend
                ];
            });
    }

    /**
     * Gastos por cartão de crédito (para gráfico de pizza)
     */
    private function getExpensesByCreditCard($userId, $month, $year)
    {
        return Transaction::with('creditCard')
            ->where('user_id', $userId)
            ->whereNotNull('credit_card_id')
            ->selectRaw('credit_card_id, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('credit_card_id')
            ->orderBy('total', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'credit_card_id' => $item->credit_card_id,
                    'credit_card_name' => $item->creditCard ? $item->creditCard->name : 'Cartão não identificado',
                    'credit_card_institution' => $item->creditCard ? $item->creditCard->institution : 'N/A',
                    'credit_card_brand' => $item->creditCard ? $item->creditCard->brand : 'N/A',
                    'credit_card_color' => $item->creditCard ? $item->creditCard->color : '#cccccc',
                    'total' => round($item->total, 2),
                    'count' => $item->count,
                    'percentage' => 0, // Será calculado no frontend
                ];
            });
    }

    /**
     * Gastos por dia (para gráfico de barras)
     */
    private function getExpensesByDay($userId, $month, $year)
    {
        return Transaction::where('user_id', $userId)
            ->selectRaw('DATE(transaction_date) as date, SUM(amount) as total')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'day' => Carbon::parse($item->date)->day,
                    'total' => round($item->total, 2),
                ];
            });
    }

    /**
     * Top estabelecimentos
     */
    private function getTopEstablishments($userId, $month, $year, $limit = 10)
    {
        return Transaction::where('user_id', $userId)
            ->whereNotNull('establishment')
            ->selectRaw('establishment, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('establishment')
            ->orderBy('total', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'establishment' => $item->establishment,
                    'total' => round($item->total, 2),
                    'count' => $item->count,
                ];
            });
    }

    /**
     * Insights e destaques
     */
    private function getInsights($userId, $month, $year)
    {
        // Categoria que mais gastou
        $topCategory = Transaction::with('category')
            ->where('user_id', $userId)
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderBy('total', 'desc')
            ->first();

        // Estabelecimento que mais gastou
        $topEstablishment = Transaction::where('user_id', $userId)
            ->whereNotNull('establishment')
            ->selectRaw('establishment, SUM(amount) as total')
            ->groupBy('establishment')
            ->orderBy('total', 'desc')
            ->first();

        // Maior gasto individual
        $biggestExpense = Transaction::where('user_id', $userId)
            ->orderBy('amount', 'desc')
            ->first();

        // Dia que mais gastou
        $biggestDay = Transaction::where('user_id', $userId)
            ->selectRaw('DATE(transaction_date) as date, SUM(amount) as total')
            ->groupBy('date')
            ->orderBy('total', 'desc')
            ->first();

        return [
            'top_category' => $topCategory ? [
                'name' => $topCategory->category ? $topCategory->category->name : 'Sem categoria',
                'total' => round($topCategory->total, 2),
            ] : null,
            'top_establishment' => $topEstablishment ? [
                'name' => $topEstablishment->establishment,
                'total' => round($topEstablishment->total, 2),
            ] : null,
            'biggest_expense' => $biggestExpense ? [
                'description' => $biggestExpense->description,
                'amount' => round($biggestExpense->amount, 2),
                'date' => $biggestExpense->transaction_date->format('d/m/Y'),
            ] : null,
            'biggest_day' => $biggestDay ? [
                'date' => Carbon::parse($biggestDay->date)->format('d/m/Y'),
                'total' => round($biggestDay->total, 2),
            ] : null,
        ];
    }

    /**
     * Comparação mensal (últimos 6 meses)
     */
    private function getMonthlyComparison($userId, $months = 6, $filterMonth = null, $filterYear = null)
    {
        // Se há filtro específico, retorna apenas os dados do mês filtrado
        if ($filterMonth && $filterYear) {
            $total = Transaction::where('user_id', $userId)
                ->whereMonth('transaction_date', $filterMonth)
                ->whereYear('transaction_date', $filterYear)
                ->sum('amount');

            $date = Carbon::create($filterYear, $filterMonth, 1);
            
            return [[
                'month' => $date->format('M/Y'),
                'month_name' => $date->translatedFormat('M Y'),
                'total' => round($total, 2),
                'year' => (int)$filterYear,
                'month_number' => (int)$filterMonth,
            ]];
        }

        // Comportamento original quando não há filtro
        $result = [];
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $month = $date->month;
            $year = $date->year;

            $total = Transaction::where('user_id', $userId)
                ->whereMonth('transaction_date', $month)
                ->whereYear('transaction_date', $year)
                ->sum('amount');

            $result[] = [
                'month' => $date->format('M/Y'),
                'month_name' => $date->translatedFormat('M Y'),
                'total' => round($total, 2),
                'year' => $year,
                'month_number' => $month,
            ];
        }

        return $result;
    }

    /**
     * Filtrar dashboard por período
     */
    public function filterByPeriod(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $userId = $request->user()->id;
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        return response()->json([
            'success' => true,
            'dashboard' => [
                'period_summary' => $this->getPeriodSummary($userId, $startDate, $endDate),
                'expenses_by_category' => $this->getExpensesByCategoryPeriod($userId, $startDate, $endDate),
                'daily_expenses' => $this->getDailyExpensesPeriod($userId, $startDate, $endDate),
                'top_establishments' => $this->getTopEstablishmentsPeriod($userId, $startDate, $endDate),
            ]
        ]);
    }

    private function getPeriodSummary($userId, $startDate, $endDate)
    {
        return Transaction::where('user_id', $userId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as total_transactions,
                SUM(amount) as total_amount,
                AVG(amount) as average_amount,
                MAX(amount) as highest_amount,
                MIN(amount) as lowest_amount
            ')
            ->first();
    }

    private function getExpensesByCategoryPeriod($userId, $startDate, $endDate)
    {
        return Transaction::with('category')
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->selectRaw('category_id, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category_id')
            ->orderBy('total', 'desc')
            ->get();
    }

    private function getDailyExpensesPeriod($userId, $startDate, $endDate)
    {
        return Transaction::where('user_id', $userId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->selectRaw('DATE(transaction_date) as date, SUM(amount) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    private function getTopEstablishmentsPeriod($userId, $startDate, $endDate)
    {
        return Transaction::where('user_id', $userId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->whereNotNull('establishment')
            ->selectRaw('establishment, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('establishment')
            ->orderBy('total', 'desc')
            ->limit(10)
            ->get();
    }

    /**
     * Filtrar dashboard por mês específico
     */
    public function filterByMonth(Request $request)
    {
        $userId = $request->user()->id;
        $month = $request->input('month', now()->month);
        $year = $request->input('year', now()->year);

        return response()->json([
            'success' => true,
            'dashboard' => [
                'current_month_summary' => $this->getMonthSummary($userId, $month, $year),
                'expenses_by_category' => $this->getExpensesByCategory($userId, $month, $year),
                'expenses_by_day' => $this->getExpensesByDay($userId, $month, $year),
                'top_establishments' => $this->getTopEstablishments($userId, $month, $year),
                'insights' => $this->getInsights($userId, $month, $year),
                'monthly_comparison' => $this->getMonthlyComparison($userId, null, $month, $year),
                'monthly_totals' => $this->getMonthlyTotals($userId, $month, $year),
                'selected_month' => $month,
                'selected_year' => $year
            ]
        ]);
    }

    /**
     * Resumo para um mês específico
     */
    private function getMonthSummary($userId, $month, $year)
    {
        $transactions = Transaction::where('user_id', $userId)
            ->whereMonth('transaction_date', $month)
            ->whereYear('transaction_date', $year)
            ->get();

        if ($transactions->isEmpty()) {
            return [
                'total_amount' => 0,
                'total_transactions' => 0,
                'daily_average' => 0,
                'month_projection' => 0
            ];
        }

        $totalAmount = $transactions->sum('amount');
        $totalTransactions = $transactions->count();
        $daysInMonth = Carbon::create($year, $month)->daysInMonth;
        $currentDay = now()->day;
        
        return [
            'total_amount' => round($totalAmount, 2),
            'total_transactions' => $totalTransactions,
            'daily_average' => round($totalAmount / $daysInMonth, 2),
            'month_projection' => round(($totalAmount / $currentDay) * $daysInMonth, 2)
        ];
    }

    /**
     * Totais mensais para gráfico comparativo
     */
    private function getMonthlyTotals($userId, $filterMonth = null, $filterYear = null)
    {
        $query = Transaction::where('user_id', $userId);
        
        // Se há filtro específico, aplica o filtro
        if ($filterMonth && $filterYear) {
            $query->whereMonth('transaction_date', $filterMonth)
                  ->whereYear('transaction_date', $filterYear);
        }
        
        return $query->selectRaw('YEAR(transaction_date) as year, MONTH(transaction_date) as month, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->limit($filterMonth && $filterYear ? 1 : 12)
            ->get()
            ->map(function ($item) {
                return [
                    'year' => $item->year,
                    'month' => $item->month,
                    'month_name' => Carbon::create($item->year, $item->month)->format('M/Y'),
                    'total' => round($item->total, 2),
                    'count' => $item->count,
                ];
            })
            ->reverse()
            ->values();
    }

    /**
     * Meses disponíveis para filtro
     */
    private function getAvailableMonths($userId)
    {
        return Transaction::where('user_id', $userId)
            ->selectRaw('YEAR(transaction_date) as year, MONTH(transaction_date) as month')
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'year' => $item->year,
                    'month' => $item->month,
                    'label' => Carbon::create($item->year, $item->month)->format('F Y'),
                    'short_label' => Carbon::create($item->year, $item->month)->format('M/Y'),
                ];
            });
    }
}
