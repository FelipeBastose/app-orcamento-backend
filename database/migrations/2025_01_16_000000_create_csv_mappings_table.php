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
        Schema::create('csv_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_card_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Nome do mapeamento (ex: "Nubank Padrão", "Inter Padrão")
            $table->string('institution'); // Instituição (ex: Nubank, Inter)
            $table->json('column_mapping'); // Mapeamento das colunas do CSV
            $table->json('date_format'); // Formatos de data aceitos
            $table->json('amount_format'); // Configurações de formato de valor
            $table->string('delimiter', 1)->default(','); // Delimitador do CSV
            $table->boolean('has_header')->default(true); // Se o CSV tem cabeçalho
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Índices para performance
            $table->index(['credit_card_id', 'is_active']);
            $table->index(['institution']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('csv_mappings');
    }
};

