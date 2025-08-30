<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'institution',
        'brand',
        'last_digits',
        'color',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Relacionamento com usuário
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento com transações
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Scope para cartões ativos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para cartões por instituição
     */
    public function scopeByInstitution($query, $institution)
    {
        return $query->where('institution', $institution);
    }

    /**
     * Scope para cartões por bandeira
     */
    public function scopeByBrand($query, $brand)
    {
        return $query->where('brand', $brand);
    }
}



