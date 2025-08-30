<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CsvMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_card_id',
        'name',
        'institution',
        'column_mapping',
        'date_format',
        'amount_format',
        'delimiter',
        'has_header',
        'is_active'
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'date_format' => 'array',
        'amount_format' => 'array',
        'has_header' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Relacionamento com cartão de crédito
     */
    public function creditCard()
    {
        return $this->belongsTo(CreditCard::class);
    }

    /**
     * Scope para mapeamentos ativos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para mapeamentos por instituição
     */
    public function scopeByInstitution($query, $institution)
    {
        return $query->where('institution', $institution);
    }

    /**
     * Scope para mapeamentos por cartão
     */
    public function scopeByCreditCard($query, $creditCardId)
    {
        return $query->where('credit_card_id', $creditCardId);
    }

    /**
     * Obter mapeamento padrão para uma instituição
     */
    public static function getDefaultForInstitution($institution)
    {
        return static::where('institution', $institution)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Obter mapeamento para um cartão específico
     */
    public static function getForCreditCard($creditCardId)
    {
        return static::where('credit_card_id', $creditCardId)
            ->where('is_active', true)
            ->first();
    }
}

