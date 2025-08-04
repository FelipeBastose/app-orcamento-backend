<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Category;
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
                'expenses_by_day' => $this->getExpensesByDay($userId, $currentMonth, $currentYear),
                'top_establishments' => $this->getTopEstablishments($userId, $currentMonth, $currentYear),
                'insights' => $this->getInsights($userId, $currentMonth, $currentYear),
                'monthly_comparison' => $this->getMonthlyComparison($userId),
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
    private function getMonthlyComparison($userId, $months = 6)
    {
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
}
