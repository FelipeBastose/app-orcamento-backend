<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Nome do cartão (ex: "Cartão Principal")
            $table->string('institution'); // Instituição (ex: Nubank, Inter, Itaú)
            $table->string('brand'); // Bandeira (ex: Visa, Mastercard, Elo)
            $table->string('last_digits', 4)->nullable(); // Últimos 4 dígitos
            $table->string('color', 7)->default('#3B82F6'); // Cor do cartão (hex)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Índices para performance
            $table->index(['user_id', 'is_active']);
            $table->index(['institution']);
            $table->index(['brand']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};

