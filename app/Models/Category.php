<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'color',
        'icon',
        'is_default'
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Relacionamento com transações
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Scope para categorias padrão
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
