<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ResellerProductPage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ResellerProductPageController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base) ?: 'product-page';
        $candidate = $slug;
        $counter = 1;

        while (
            ResellerProductPage::where('slug', $candidate)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $slug . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function normalizePrice($value): ?float
    {
        return is_null($value) ? null : round((float) $value, 2);
    }

    private function resolveProductAdminPrice(Product $product): ?float
    {
        if (!is_null($product->admin_price)) {
            return $this->normalizePrice($product->admin_price);
        }

        return !is_null($product->unit_price) ? (float) ceil((float) $product->unit_price * 1.05) : null;
    }

    private function validatePagePrices(Product $product, $sellingPrice, $discountPrice = null): ?array
    {
        $adminPrice = $this->resolveProductAdminPrice($product);
        $sellingPrice = $this->normalizePrice($sellingPrice);
        $discountPrice = $this->normalizePrice($discountPrice);

        if (!is_null($adminPrice) && !is_null($sellingPrice) && $sellingPrice < $adminPrice) {
            return [
                'field' => 'selling_price',
                'product_id' => $product->id,
                'admin_price' => $adminPrice,
                'selling_price' => $sellingPrice,
            ];
        }

        if (!is_null($adminPrice) && !is_null($discountPrice) && $discountPrice < $adminPrice) {
            return [
                'field' => 'discount_price',
                'product_id' => $product->id,
                'admin_price' => $adminPrice,
                'discount_price' => $discountPrice,
            ];
        }

        return null;
    }

    private function templateOptions(): array
    {
        return ['default', 'modern', 'classic', 'minimal', 'bold'];
    }

    private function designValidationRules(bool $partial = false): array
    {
        $baseRule = $partial ? 'sometimes' : 'nullable';

        return [
            'template_id' => [$baseRule, 'nullable', 'string', Rule::in($this->templateOptions())],
            'design_settings' => [$baseRule, 'nullable', 'array'],
            'design_settings.theme.primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design_settings.theme.secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design_settings.theme.background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design_settings.theme.text_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design_settings.theme.button_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design_settings.theme.button_text_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design_settings.theme.font_style' => ['nullable', 'string', 'max:50'],
            'design_settings.layout.style' => ['nullable', 'string', Rule::in(['default', 'classic', 'modern', 'minimal', 'bold'])],
            'design_settings.layout.hero_alignment' => ['nullable', 'string', Rule::in(['left', 'center', 'right'])],
            'design_settings.layout.image_position' => ['nullable', 'string', Rule::in(['top', 'left', 'right', 'background'])],
            'design_settings.hero.badge_text' => ['nullable', 'string', 'max:80'],
            'design_settings.hero.cta_text' => ['nullable', 'string', 'max:50'],
            'design_settings.hero.subtitle' => ['nullable', 'string', 'max:255'],
            'design_settings.sections.show_gallery' => ['nullable', 'boolean'],
            'design_settings.sections.show_benefits' => ['nullable', 'boolean'],
            'design_settings.sections.show_reviews' => ['nullable', 'boolean'],
            'design_settings.sections.show_faq' => ['nullable', 'boolean'],
            'design_settings.sections.show_delivery_info' => ['nullable', 'boolean'],
            'design_settings.sections.show_whatsapp_button' => ['nullable', 'boolean'],
            'design_settings.benefits' => ['nullable', 'array', 'max:8'],
            'design_settings.benefits.*' => ['nullable', 'string', 'max:120'],
            'design_settings.faq' => ['nullable', 'array', 'max:10'],
            'design_settings.faq.*.question' => ['nullable', 'string', 'max:150'],
            'design_settings.faq.*.answer' => ['nullable', 'string', 'max:300'],
        ];
    }

    private function hasUnsafeDesignValue($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->hasUnsafeDesignValue($key)) {
                    return true;
                }

                if ($this->hasUnsafeDesignValue($item)) {
                    return true;
                }
            }

            return false;
        }

        if (!is_string($value)) {
            return false;
        }

        $patterns = [
            '/<\s*script\b/i',
            '/<\/\s*script\s*>/i',
            '/<\s*iframe\b/i',
            '/<\/\s*iframe\s*>/i',
            '/<\s*style\b/i',
            '/<\/\s*style\s*>/i',
            '/\bon\w+\s*=/i',
            '/javascript\s*:/i',
            '/https?:\/\/[^\s"\']+\.js(?:\?[^\s"\']*)?/i',
            '/\bexpression\s*\(/i',
            '/(?:^|\s)[.#]?[a-z][a-z0-9_-]*\s*\{[^}]*\}/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    private function validateDesignSafety(?array $settings): ?array
    {
        if (!$settings) {
            return null;
        }

        if ($this->hasUnsafeDesignValue($settings)) {
            return [
                'design_settings' => [
                    'Design settings can not contain raw HTML, CSS, JavaScript, event handlers, iframes, or external script URLs.',
                ],
            ];
        }

        return null;
    }

    private function onlyAllowedKeys(array $source, array $keys): array
    {
        return array_intersect_key($source, array_flip($keys));
    }

    private function normalizeDesignSettings(?array $settings): ?array
    {
        if (is_null($settings)) {
            return null;
        }

        $normalized = $this->onlyAllowedKeys($settings, [
            'theme',
            'layout',
            'hero',
            'sections',
            'benefits',
            'faq',
        ]);

        if (isset($normalized['theme']) && is_array($normalized['theme'])) {
            $normalized['theme'] = $this->onlyAllowedKeys($normalized['theme'], [
                'primary_color',
                'secondary_color',
                'background_color',
                'text_color',
                'button_color',
                'button_text_color',
                'font_style',
            ]);
        }

        if (isset($normalized['layout']) && is_array($normalized['layout'])) {
            $normalized['layout'] = $this->onlyAllowedKeys($normalized['layout'], [
                'style',
                'hero_alignment',
                'image_position',
            ]);
        }

        if (isset($normalized['hero']) && is_array($normalized['hero'])) {
            $normalized['hero'] = $this->onlyAllowedKeys($normalized['hero'], [
                'badge_text',
                'cta_text',
                'subtitle',
            ]);
        }

        if (isset($normalized['sections']) && is_array($normalized['sections'])) {
            $normalized['sections'] = $this->onlyAllowedKeys($normalized['sections'], [
                'show_gallery',
                'show_benefits',
                'show_reviews',
                'show_faq',
                'show_delivery_info',
                'show_whatsapp_button',
            ]);
        }

        if (isset($normalized['faq']) && is_array($normalized['faq'])) {
            $normalized['faq'] = array_map(function ($item) {
                return is_array($item)
                    ? $this->onlyAllowedKeys($item, ['question', 'answer'])
                    : $item;
            }, $normalized['faq']);
        }

        return $normalized;
    }

    public function list(Request $request)
    {
        try {
            $query = ResellerProductPage::with(['reseller', 'product.images.image', 'product.primaryImage']);

            if ($request->filled('reseller_id')) {
                $query->where('reseller_id', $request->reseller_id);
            }

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            if ($request->filled('published_status')) {
                $query->where('published_status', $request->published_status);
            }

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Reseller product pages fetched successfully', $query->latest()->get());
            }

            $perPage = (int) $request->get('per_page', 20);

            return $this->success('Reseller product pages fetched successfully', $query->latest()->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function add(Request $request)
    {
        try {
            $validated = $request->validate([
                'reseller_id' => ['required', 'integer', 'exists:users,id'],
                'product_id' => [
                    'required',
                    'integer',
                    'exists:products,id',
                    Rule::unique('reseller_product_pages', 'product_id')
                        ->where(fn ($query) => $query->where('reseller_id', $request->reseller_id)),
                ],
                'slug' => ['nullable', 'string', 'max:255', 'unique:reseller_product_pages,slug'],
                'selling_price' => ['required', 'numeric', 'min:0'],
                'discount_price' => ['nullable', 'numeric', 'min:0'],
                'custom_title' => ['nullable', 'string', 'max:255'],
                'custom_description' => ['nullable', 'string'],
                'delivery_charge' => ['nullable', 'numeric', 'min:0'],
                'published_status' => ['nullable', 'string', 'max:50'],
            ] + $this->designValidationRules());

            $designError = $this->validateDesignSafety($validated['design_settings'] ?? null);

            if ($designError) {
                return $this->failed('Validation failed', $designError, 422);
            }

            if (array_key_exists('design_settings', $validated)) {
                $validated['design_settings'] = $this->normalizeDesignSettings($validated['design_settings']);
            }

            $product = Product::find($validated['product_id']);
            $priceError = $product
                ? $this->validatePagePrices($product, $validated['selling_price'], $validated['discount_price'] ?? null)
                : null;

            if ($priceError) {
                return $this->failed('Product page price can not be less than admin price', $priceError, 422);
            }

            if (empty($validated['slug'])) {
                $validated['slug'] = $this->uniqueSlug($validated['custom_title'] ?? $product?->name ?? 'product-page');
            }

            $validated['template_id'] = $validated['template_id'] ?? 'default';
            $validated['design_version'] = 1;

            $page = ResellerProductPage::create($validated);

            return $this->success('Reseller product page created successfully', $page->load([
                'reseller',
                'product.images.image',
                'product.primaryImage',
            ]), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function details($id)
    {
        try {
            $page = ResellerProductPage::with(['reseller', 'product.images.image', 'product.primaryImage'])->find($id);

            if (!$page) {
                return $this->failed('Reseller product page not found', null, 404);
            }

            return $this->success('Reseller product page fetched successfully', $page);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function getBySlug($slug)
    {
        try {
            $page = ResellerProductPage::with(['reseller', 'product.images.image', 'product.primaryImage'])
                ->where('slug', $slug)
                ->first();

            if (!$page) {
                return $this->failed('Reseller product page not found', null, 404);
            }

            return $this->success('Reseller product page fetched successfully', $page);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $page = ResellerProductPage::find($id);

            if (!$page) {
                return $this->failed('Reseller product page not found', null, 404);
            }

            $validated = $request->validate([
                'reseller_id' => ['sometimes', 'integer', 'exists:users,id'],
                'product_id' => ['sometimes', 'integer', 'exists:products,id'],
                'slug' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('reseller_product_pages', 'slug')->ignore($page->id),
                ],
                'selling_price' => ['sometimes', 'numeric', 'min:0'],
                'discount_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'custom_title' => ['sometimes', 'nullable', 'string', 'max:255'],
                'custom_description' => ['sometimes', 'nullable', 'string'],
                'delivery_charge' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'published_status' => ['sometimes', 'nullable', 'string', 'max:50'],
            ] + $this->designValidationRules(true));

            $designError = $this->validateDesignSafety($validated['design_settings'] ?? null);

            if ($designError) {
                return $this->failed('Validation failed', $designError, 422);
            }

            if (array_key_exists('design_settings', $validated)) {
                $validated['design_settings'] = $this->normalizeDesignSettings($validated['design_settings']);
            }

            $resellerId = $validated['reseller_id'] ?? $page->reseller_id;
            $productId = $validated['product_id'] ?? $page->product_id;
            $exists = ResellerProductPage::where('reseller_id', $resellerId)
                ->where('product_id', $productId)
                ->where('id', '!=', $page->id)
                ->exists();

            if ($exists) {
                return $this->failed('This reseller already has a page for this product', null, 422);
            }

            $shouldValidatePrice = array_key_exists('product_id', $validated)
                || array_key_exists('selling_price', $validated)
                || array_key_exists('discount_price', $validated);

            if ($shouldValidatePrice) {
                $product = Product::find($productId);
                $sellingPrice = array_key_exists('selling_price', $validated)
                    ? $validated['selling_price']
                    : $page->selling_price;
                $discountPrice = array_key_exists('discount_price', $validated)
                    ? $validated['discount_price']
                    : $page->discount_price;
                $priceError = $product
                    ? $this->validatePagePrices($product, $sellingPrice, $discountPrice)
                    : null;

                if ($priceError) {
                    return $this->failed('Product page price can not be less than admin price', $priceError, 422);
                }
            }

            if (array_key_exists('slug', $validated) && empty($validated['slug'])) {
                $product = $product ?? Product::find($productId);
                $validated['slug'] = $this->uniqueSlug($validated['custom_title'] ?? $product?->name ?? 'product-page', $page->id);
            }

            if (array_key_exists('template_id', $validated) && is_null($validated['template_id'])) {
                $validated['template_id'] = 'default';
            }

            $designTouched = array_key_exists('template_id', $validated)
                || array_key_exists('design_settings', $validated);

            if ($designTouched) {
                $validated['design_version'] = max(1, (int) $page->design_version + 1);
            }

            $page->update($validated);

            return $this->success('Reseller product page updated successfully', $page->load([
                'reseller',
                'product.images.image',
                'product.primaryImage',
            ]));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function updateDesign(Request $request, $id)
    {
        try {
            $page = ResellerProductPage::find($id);

            if (!$page) {
                return $this->failed('Reseller product page not found', null, 404);
            }

            $validated = $request->validate($this->designValidationRules(true));

            if (
                !array_key_exists('template_id', $validated)
                && !array_key_exists('design_settings', $validated)
            ) {
                return $this->failed('Validation failed', [
                    'design' => ['template_id or design_settings is required.'],
                ], 422);
            }

            $designError = $this->validateDesignSafety($validated['design_settings'] ?? null);

            if ($designError) {
                return $this->failed('Validation failed', $designError, 422);
            }

            if (array_key_exists('design_settings', $validated)) {
                $validated['design_settings'] = $this->normalizeDesignSettings($validated['design_settings']);
            }

            if (array_key_exists('template_id', $validated) && is_null($validated['template_id'])) {
                $validated['template_id'] = 'default';
            }

            $validated['design_version'] = max(1, (int) $page->design_version + 1);

            $page->update($validated);

            return $this->success('Reseller product page design updated successfully', $page->load([
                'reseller',
                'product.images.image',
                'product.primaryImage',
            ]));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function remove($id)
    {
        try {
            $page = ResellerProductPage::find($id);

            if (!$page) {
                return $this->failed('Reseller product page not found', null, 404);
            }

            $page->delete();

            return $this->success('Reseller product page removed successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
