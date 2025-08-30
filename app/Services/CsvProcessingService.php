<?php

namespace App\Services;

use App\Models\CsvMapping;
use App\Models\Transaction;
use App\Models\CreditCard;
use App\Services\AICategorizationService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CsvProcessingService
{
    protected $aiService;

    public function __construct(AICategorizationService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Processar arquivo CSV usando mapeamento específico
     */
    public function processCSVFile($filePath, $userId, $creditCardId = null)
    {
        $processedCount = 0;
        $errors = [];
        $duplicates = 0;

        // Obter mapeamento apropriado
        $mapping = $this->getMappingForProcessing($creditCardId);
        if (!$mapping) {
            throw new \Exception('Nenhum mapeamento de CSV encontrado para o cartão selecionado');
        }

        if (($handle = fopen($filePath, 'r')) !== false) {
            // Pular cabeçalho se configurado
            if ($mapping->has_header) {
                $header = fgetcsv($handle, 1000, $mapping->delimiter);
            }
            
            while (($data = fgetcsv($handle, 1000, $mapping->delimiter)) !== false) {
                try {
                    $transactionData = $this->parseCSVRowWithMapping($data, $userId, $creditCardId, $mapping);
                    
                    if ($transactionData) {
                        // Verificar duplicatas
                        $exists = Transaction::where('user_id', $userId)
                            ->where('transaction_date', $transactionData['transaction_date'])
                            ->where('description', $transactionData['description'])
                            ->where('amount', $transactionData['amount'])
                            ->exists();

                        if (!$exists) {
                            $transaction = Transaction::create($transactionData);
                            
                            // Classificar automaticamente com IA
                            try {
                                $aiResult = $this->aiService->categorizeTransaction($transaction);
                                if ($aiResult['category_id'] && $aiResult['confidence'] >= 0.3) {
                                    $transaction->update([
                                        'category_id' => $aiResult['category_id'],
                                        'is_categorized_by_ai' => true,
                                        'ai_confidence' => $aiResult['confidence']
                                    ]);
                                }
                            } catch (\Exception $e) {
                                Log::warning('Erro na classificação por IA: ' . $e->getMessage(), [
                                    'transaction_id' => $transaction->id
                                ]);
                            }
                            
                            $processedCount++;
                        } else {
                            $duplicates++;
                        }
                    }
                } catch (\Exception $e) {
                    $errors[] = 'Erro na linha: ' . $e->getMessage();
                    Log::warning('Erro ao processar linha CSV: ' . $e->getMessage(), [
                        'data' => $data,
                        'mapping' => $mapping->id
                    ]);
                }
            }
            fclose($handle);
        }

        return [
            'processed' => $processedCount,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'mapping_used' => $mapping->name,
        ];
    }

    /**
     * Obter mapeamento apropriado para processamento
     */
    private function getMappingForProcessing($creditCardId)
    {
        if ($creditCardId) {
            // Tentar mapeamento específico do cartão
            $mapping = CsvMapping::getForCreditCard($creditCardId);
            if ($mapping) {
                return $mapping;
            }

            // Se não encontrar, buscar por instituição do cartão
            $creditCard = CreditCard::find($creditCardId);
            if ($creditCard) {
                $mapping = CsvMapping::getDefaultForInstitution($creditCard->institution);
                if ($mapping) {
                    return $mapping;
                }
            }
        }

        // Fallback para mapeamento padrão (Nubank)
        return CsvMapping::getDefaultForInstitution('Nubank');
    }

    /**
     * Parsear linha do CSV usando mapeamento
     */
    private function parseCSVRowWithMapping($data, $userId, $creditCardId, CsvMapping $mapping)
    {
        $columnMapping = $mapping->column_mapping;
        
        // Verificar se temos dados suficientes
        $maxColumn = max(array_values($columnMapping));
        if (count($data) <= $maxColumn) {
            throw new \Exception("Linha CSV não possui colunas suficientes. Esperado: {$maxColumn}, Encontrado: " . count($data));
        }

        try {
            // Extrair dados usando mapeamento
            $dateString = trim($data[$columnMapping['date']]);
            $description = trim($data[$columnMapping['description']]);
            $amountString = trim($data[$columnMapping['amount']]);
            
            // Processar data
            $date = $this->parseDate($dateString, $mapping->date_format);
            if (!$date) {
                throw new \Exception("Formato de data inválido: {$dateString}");
            }

            // Processar valor
            Log::info('Processando valor: ' . $amountString, [
                'amount_format' => $mapping->amount_format,
                'institution' => $mapping->institution
            ]);
            
            // Criar array com amount_format + instituição para o parseAmount
            $amountConfig = array_merge($mapping->amount_format ?? [], ['institution' => $mapping->institution]);
            $amount = $this->parseAmount($amountString, $amountConfig);
            Log::info('Valor processado: ' . $amount);
            
            if ($amount == 0) {
                throw new \Exception("Valor inválido: {$amountString}");
            }

            // Extrair estabelecimento
            $establishment = $this->extractEstablishment($description, $mapping->institution);

            // Extrair informações adicionais se disponíveis
            $category = isset($columnMapping['category']) ? trim($data[$columnMapping['category']]) : null;
            $type = isset($columnMapping['type']) ? trim($data[$columnMapping['type']]) : null;

            return [
                'user_id' => $userId,
                'credit_card_id' => $creditCardId,
                'transaction_date' => $date->format('Y-m-d'),
                'description' => $description,
                'establishment' => $establishment,
                'amount' => $amount,
                'raw_description' => $description,
                'metadata' => [
                    'original_row' => $data,
                    'imported_at' => now()->toISOString(),
                    'original_date' => $dateString,
                    'original_amount' => $amountString,
                    'csv_mapping_id' => $mapping->id,
                    'institution' => $mapping->institution,
                    'category_from_csv' => $category,
                    'type_from_csv' => $type,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Erro ao parsear linha CSV com mapeamento: ' . $e->getMessage(), [
                'data' => $data,
                'mapping' => $mapping->id
            ]);
            throw $e;
        }
    }

    /**
     * Parsear data usando formatos configurados
     */
    private function parseDate($dateString, $dateFormats)
    {
        foreach ($dateFormats as $format) {
            try {
                return Carbon::createFromFormat($format, $dateString);
            } catch (\Exception $e) {
                continue;
            }
        }
        return null;
    }

    /**
     * Parsear valor monetário usando configurações
     */
    private function parseAmount($amountString, $amountFormat = null)
    {
        $rawAmount = $amountString;

        // Remover símbolo de moeda (R$, US$, etc)
        if (!empty($amountFormat['currency_symbol'])) {
            $rawAmount = str_replace($amountFormat['currency_symbol'], '', $rawAmount);
        } else {
            $rawAmount = str_replace(['R$', '$'], '', $rawAmount);
        }

        // PRIMEIRO: trocar vírgula por ponto (formato brasileiro)
        $rawAmount = str_replace(',', '.', $rawAmount);
        
        // DEPOIS: remover TODOS os espaços e caracteres especiais, manter apenas números, ponto e sinal negativo
        $rawAmount = preg_replace('/[^\d.\-]/', '', $rawAmount);

        // Converter para float - garantir que seja ponto como decimal
        $amount = (float) str_replace(',', '.', $rawAmount);

        // Para Nubank: valores negativos são receitas
        if (!empty($amountFormat['negative_values_are_income'])) {
            return $amount;
        }

        // Outros bancos: sempre valor absoluto
        return abs($amount);
    }

    /**
     * Extrair estabelecimento baseado na instituição
     */
    private function extractEstablishment($description, $institution)
    {
        switch (strtolower($institution)) {
            case 'nubank':
                // Remover padrões comuns do Nubank
                $cleaned = preg_replace('/^(Compra no débito|Compra no crédito|PIX|TED)\s*-?\s*/i', '', $description);
                $cleaned = preg_replace('/\s*-\s*\d{2}\/\d{2}$/', '', $cleaned);
                break;
                
            case 'inter':
                // Para Inter, manter descrição mais limpa
                $cleaned = preg_replace('/\s*-\s*\d+\/\d+.*$/', '', $description);
                break;
                
            default:
                $cleaned = $description;
        }
        
        // Pegar primeira parte antes de hífen ou número
        $parts = preg_split('/\s*-\s*|\s+\d/', $cleaned, 2);
        return trim($parts[0]);
    }
}
