<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'transaction_date',
        'description',
        'establishment',
        'amount',
        'card_last_digits',
        'transaction_id',
        'raw_description',
        'is_categorized_by_ai',
        'ai_confidence',
        'metadata'
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'is_categorized_by_ai' => 'boolean',
        'ai_confidence' => 'float',
        'metadata' => 'array',
    ];

    /**
     * Relacionamento com usuário
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento com categoria
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Scope para transações do mês atual
     */
    public function scopeCurrentMonth($query)
    {
        return $query->whereMonth('transaction_date', now()->month)
                    ->whereYear('transaction_date', now()->year);
    }

    /**
     * Scope para transações por período
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }

    /**
     * Scope para transações por categoria
     */
    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }
}
