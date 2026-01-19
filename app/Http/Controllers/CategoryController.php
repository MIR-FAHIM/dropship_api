<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors
        ], $code);
    }

    /**
     * POST /categories/create
     */
    public function createCategory(Request $request)
    {
        try {
            $validated = $request->validate([
                'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
                'name' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255', 'unique:categories,slug'],
                'icon' => ['nullable', 'string', 'max:255'],
                'image' => ['nullable', 'string', 'max:255'],
                'sort_order' => ['nullable', 'integer'],
                'status' => ['nullable', 'string', 'max:50'],
            ]);

            $category = Category::create($validated);

            return $this->success('Category created successfully', $category, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /categories/list?parent_id=&status=&per_page=
     */
    public function listCategories(Request $request)
    {
        try {
            $query = Category::query()
                ->with(['children' => function ($q) {
                    $q->orderByRaw('COALESCE(order_level, 999999) asc')
                      ->latest();
                }]);

            // If a specific parent_id is provided, filter by it (0 is top-level)
            if ($request->filled('parent_id')) {
                $query->where('parent_id', (int) $request->parent_id);
            }

            // Default: top-level categories (parent_id === 0) only if nothing provided
            if (!$request->filled('parent_id') && !$request->filled('all')) {
                $query->where('parent_id', 0);
            }

            $perPage = (int) $request->get('per_page', 20);

            // If you want all (no pagination): /categories/list?all=1
            if ($request->filled('all') && (int) $request->get('all') === 1) {
                $categories = $query->orderByRaw('COALESCE(order_level, 999999) asc')
                    ->latest()
                    ->get();

                return $this->success('Categories fetched successfully', $categories);
            }

            $categories = $query->orderByRaw('COALESCE(order_level, 999999) asc')
                ->latest()
                ->paginate($perPage);

            return $this->success('Categories fetched successfully', $categories);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /categories/details/{id}
     */
    public function getCategoryDetails($id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->failed('Category not found', null, 404);
            }

            return $this->success('Category fetched successfully', $category);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /categories/update/{id}
     */
    public function updateCategory(Request $request, $id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->failed('Category not found', null, 404);
            }

            $validated = $request->validate([
                'parent_id' => ['nullable', 'integer', 'exists:categories,id', Rule::notIn([$id])],
                'name' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($category->id)],
                'icon' => ['nullable', 'string', 'max:255'],
                'image' => ['nullable', 'string', 'max:255'],
                'sort_order' => ['nullable', 'integer'],
                'status' => ['nullable', 'string', 'max:50'],
            ]);

            $category->fill($validated);
            $category->save();

            return $this->success('Category updated successfully', $category);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /categories/delete/{id}
     */
    public function deleteCategory($id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->failed('Category not found', null, 404);
            }

            // Prevent deleting category that still has children
            $hasChildren = Category::where('parent_id', $category->id)->exists();
            if ($hasChildren) {
                return $this->failed('Cannot delete: category has sub-categories. Delete sub-categories first.', null, 409);
            }

            $category->delete();

            return $this->success('Category deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /categories/children/{id}
     */
    public function getCategoryChildren($id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->failed('Category not found', null, 404);
            }

            $children = Category::where('parent_id', $id)
                ->orderByRaw('COALESCE(order_level, 999999) asc')
                ->latest()
                ->get();

            return $this->success('Sub-categories fetched successfully', $children);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
