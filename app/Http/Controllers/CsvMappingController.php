<?php

namespace App\Http\Controllers;

use App\Models\CsvMapping;
use App\Models\CreditCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CsvMappingController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Listar mapeamentos disponíveis
     */
    public function index(Request $request)
    {
        $mappings = CsvMapping::with('creditCard')
            ->where(function($query) use ($request) {
                if ($request->has('institution')) {
                    $query->where('institution', $request->institution);
                }
                if ($request->has('credit_card_id')) {
                    $query->where('credit_card_id', $request->credit_card_id);
                }
            })
            ->where('is_active', true)
            ->get();

        return response()->json([
            'success' => true,
            'mappings' => $mappings,
        ]);
    }

    /**
     * Obter mapeamento específico
     */
    public function show($id)
    {
        $mapping = CsvMapping::with('creditCard')->findOrFail($id);

        return response()->json([
            'success' => true,
            'mapping' => $mapping,
        ]);
    }

    /**
     * Criar novo mapeamento
     */
    public function store(Request $request)
    {
        $request->validate([
            'credit_card_id' => 'required|exists:credit_cards,id',
            'name' => 'required|string|max:255',
            'institution' => 'required|string|max:255',
            'column_mapping' => 'required|array',
            'date_format' => 'required|array',
            'amount_format' => 'required|array',
            'delimiter' => 'string|max:1',
            'has_header' => 'boolean',
        ]);

        // Verificar se o usuário tem acesso ao cartão
        $creditCard = CreditCard::where('user_id', $request->user()->id)
            ->findOrFail($request->credit_card_id);

        $mapping = CsvMapping::create([
            'credit_card_id' => $request->credit_card_id,
            'name' => $request->name,
            'institution' => $request->institution,
            'column_mapping' => $request->column_mapping,
            'date_format' => $request->date_format,
            'amount_format' => $request->amount_format,
            'delimiter' => $request->get('delimiter', ','),
            'has_header' => $request->get('has_header', true),
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Mapeamento criado com sucesso',
            'mapping' => $mapping->load('creditCard'),
        ], 201);
    }

    /**
     * Atualizar mapeamento
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'column_mapping' => 'sometimes|array',
            'date_format' => 'sometimes|array',
            'amount_format' => 'sometimes|array',
            'delimiter' => 'sometimes|string|max:1',
            'has_header' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $mapping = CsvMapping::with('creditCard')
            ->whereHas('creditCard', function($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->findOrFail($id);

        $mapping->update($request->only([
            'name', 'column_mapping', 'date_format', 'amount_format', 
            'delimiter', 'has_header', 'is_active'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Mapeamento atualizado com sucesso',
            'mapping' => $mapping->load('creditCard'),
        ]);
    }

    /**
     * Excluir mapeamento
     */
    public function destroy($id, Request $request)
    {
        $mapping = CsvMapping::with('creditCard')
            ->whereHas('creditCard', function($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->findOrFail($id);

        $mapping->delete();

        return response()->json([
            'success' => true,
            'message' => 'Mapeamento excluído com sucesso',
        ]);
    }

    /**
     * Obter mapeamentos por instituição
     */
    public function getByInstitution(Request $request, $institution)
    {
        $mappings = CsvMapping::where('institution', $institution)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'success' => true,
            'mappings' => $mappings,
        ]);
    }

    /**
     * Testar mapeamento com dados de exemplo
     */
    public function test(Request $request, $id)
    {
        $request->validate([
            'sample_data' => 'required|array',
        ]);

        $mapping = CsvMapping::with('creditCard')
            ->whereHas('creditCard', function($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->findOrFail($id);

        try {
            // Simular processamento de uma linha
            $sampleRow = $request->sample_data;
            $columnMapping = $mapping->column_mapping;
            
            // Verificar se temos dados suficientes
            $maxColumn = max(array_values($columnMapping));
            if (count($sampleRow) <= $maxColumn) {
                throw new \Exception("Dados de exemplo não possuem colunas suficientes");
            }

            // Extrair dados usando mapeamento
            $dateString = trim($sampleRow[$columnMapping['date']]);
            $description = trim($sampleRow[$columnMapping['description']]);
            $amountString = trim($sampleRow[$columnMapping['amount']]);

            return response()->json([
                'success' => true,
                'message' => 'Mapeamento testado com sucesso',
                'parsed_data' => [
                    'date' => $dateString,
                    'description' => $description,
                    'amount' => $amountString,
                    'category' => isset($columnMapping['category']) ? trim($sampleRow[$columnMapping['category']]) : null,
                    'type' => isset($columnMapping['type']) ? trim($sampleRow[$columnMapping['type']]) : null,
                ],
                'mapping_info' => [
                    'delimiter' => $mapping->delimiter,
                    'has_header' => $mapping->has_header,
                    'institution' => $mapping->institution,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao testar mapeamento: ' . $e->getMessage(),
            ], 400);
        }
    }
}

