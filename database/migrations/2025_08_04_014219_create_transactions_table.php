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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null');
            $table->date('transaction_date');
            $table->string('description');
            $table->string('establishment')->nullable(); // Nome do estabelecimento
            $table->decimal('amount', 10, 2);
            $table->string('card_last_digits', 4)->nullable();
            $table->string('transaction_id')->nullable(); // ID original da transação no banco
            $table->text('raw_description')->nullable(); // Descrição original do CSV
            $table->boolean('is_categorized_by_ai')->default(false);
            $table->float('ai_confidence', 3, 2)->nullable(); // Confiança da IA (0.00 a 1.00)
            $table->json('metadata')->nullable(); // Dados extras do CSV
            $table->timestamps();
            
            // Índices para performance
            $table->index(['user_id', 'transaction_date']);
            $table->index(['category_id']);
            $table->index(['establishment']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
