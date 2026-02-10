<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
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
            // Only show top-level featured categories (no children)
            $query = Category::query()
            ->with('banner')
                ->where('parent_id', 0)
                ->where('featured', 1);

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


        public function getCategoryWithAllChildren()
    {
        try {
            $categories = Category::with('banner')
                ->orderByRaw('COALESCE(order_level, 999999) asc')
                ->get();

            $byParent = $categories->groupBy(function ($category) {
                return $category->parent_id ?? 0;
            });

            $buildTree = function ($parentId) use (&$buildTree, $byParent) {
                $children = $byParent->get($parentId, collect());

                return $children->map(function ($category) use (&$buildTree) {
                    $nested = $buildTree($category->id);
                    $category->setRelation('children', $nested);
                    return $category;
                });
            };

            $tree = $buildTree(0);

            return $this->success('Categories with children fetched successfully', $tree);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

        public function listCategoryProducts(Request $request)
    {
        try {
            $query = Product::query()->with(['primaryImage', 'images', 'category', 'subCategory', 'brand', 'productDiscount', 'averageReview']);

            if ($request->filled('category_id')) {
                $categoryId = (int) $request->category_id;
                $query->where(function ($q) use ($categoryId) {
                    $q->where('category_id', $categoryId)
                        ->orWhereHas('category', function ($qc) use ($categoryId) {
                            $qc->where('parent_id', $categoryId);
                        });
                });
            }

          

            if ($request->filled('featured')) {
                $query->where('featured', $request->featured);
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', (int) $request->is_active);
            }

            if ($request->filled('search')) {
                $search = trim($request->search);
                // split into tokens so multi-word searches behave well
                $tokens = preg_split('/\s+/', $search);

                $query->where(function ($q) use ($tokens) {
                    foreach ($tokens as $token) {
                        $t = "%" . $token . "%";
                        $q->where(function ($qq) use ($t) {
                            $qq->where('name', 'like', $t)
                               
                                ->orWhere('slug', 'like', $t)
                                ->orWhereHas('category', function ($qc) use ($t) {
                                    $qc->where('name', 'like', $t);
                                })
                               
                                ->orWhereHas('brand', function ($qc) use ($t) {
                                    $qc->where('name', 'like', $t);
                                });
                        });
                    }
                });
            }

            $perPage = (int) $request->get('per_page', 20);
            $products = $query->latest()->paginate($perPage);

            return $this->success('Products fetched successfully', $products, 200,);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
