<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Listar todas as categorias
     */
    public function index()
    {
        $categories = Category::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'categories' => $categories,
        ]);
    }

    /**
     * Obter uma categoria específica
     */
    public function show($id)
    {
        $category = Category::findOrFail($id);

        return response()->json([
            'success' => true,
            'category' => $category,
        ]);
    }

    /**
     * Criar nova categoria (personalizada pelo usuário)
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories',
            'description' => 'nullable|string|max:500',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'icon' => 'nullable|string|max:50',
        ]);

        $category = Category::create([
            'name' => $request->name,
            'description' => $request->description,
            'color' => $request->color,
            'icon' => $request->icon,
            'is_default' => false, // Categorias criadas pelo usuário não são padrão
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Categoria criada com sucesso',
            'category' => $category,
        ], 201);
    }

    /**
     * Atualizar categoria
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        // Não permitir edição de categorias padrão
        if ($category->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível editar categorias padrão',
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $id,
            'description' => 'nullable|string|max:500',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'icon' => 'nullable|string|max:50',
        ]);

        $category->update($request->only(['name', 'description', 'color', 'icon']));

        return response()->json([
            'success' => true,
            'message' => 'Categoria atualizada com sucesso',
            'category' => $category,
        ]);
    }

    /**
     * Excluir categoria
     */
    public function destroy($id)
    {
        $category = Category::findOrFail($id);

        // Não permitir exclusão de categorias padrão
        if ($category->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível excluir categorias padrão',
            ], 403);
        }

        // Verificar se há transações usando esta categoria
        if ($category->transactions()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível excluir categoria que possui transações vinculadas',
            ], 409);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Categoria excluída com sucesso',
        ]);
    }
}
