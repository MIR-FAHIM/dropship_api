<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductWinningProfile as Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Partner Portal F6 — Winning Products & creative angles.
 * Admin: mark a product as winning and add hook / angle / tip / comparison photos.
 * Marketer: browse the cards (Reseller Brain price → suggested selling price; the supplier's
 * price is never sent), copy the pitch, star products for the Pitch Calculator.
 */
class WinningProductController extends Controller
{
    /* ======================= Admin ======================= */

    /** GET /admin/winning-products */
    public function adminIndex(Request $request): JsonResponse
    {
        $q = Profile::with(['product:id,name,sku,admin_price,max_resell_price,thumbnail_img,featured,approved', 'product.primaryImage:id,file_name,external_link'])
            ->orderByDesc('is_winning')->orderBy('sort_order')->latest('id');
        if ($request->input('status') === 'winning') {
            $q->where('is_winning', true);
        }

        return $this->ok('Winning products', $q->paginate(min(100, max(1, (int) $request->input('per_page', 30)))));
    }

    /** GET /admin/products/{id}/winning */
    public function adminShow(int $id): JsonResponse
    {
        return $this->ok('Winning profile', Profile::where('product_id', $id)->first());
    }

    /** POST /admin/products/{id}/winning (multipart) — create or update, and mark as winning. */
    public function adminSave(Request $request, int $id): JsonResponse
    {
        if (!Product::whereKey($id)->exists()) {
            return response()->json(['status' => 'failed', 'message' => 'Product not found', 'errors' => null], 404);
        }
        try {
            $data = $request->validate([
                'hook' => ['required', 'string', 'max:500'],
                'ad_angle' => ['required', 'string', 'max:1000'],
                'creative_tip' => ['nullable', 'string', 'max:1000'],
                'local_caption' => ['nullable', 'string', 'max:255'],
                'original_caption' => ['nullable', 'string', 'max:255'],
                'local_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
                'original_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
                'remove_local_image' => ['nullable', 'boolean'],
                'remove_original_image' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
                'is_winning' => ['nullable', 'boolean'],
            ], [
                'hook.required' => 'Add the ad hook marketers will use.',
                'ad_angle.required' => 'Add the creative angle.',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'failed', 'message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }

        $profile = Profile::firstOrNew(['product_id' => $id]);
        foreach (['local', 'original'] as $side) {
            $col = "{$side}_image";
            if ($request->boolean("remove_{$col}") && $profile->{$col}) {
                Storage::disk('public')->delete($profile->{$col});
                $profile->{$col} = null;
            }
            if ($request->hasFile($col)) {
                if ($profile->{$col}) {
                    Storage::disk('public')->delete($profile->{$col});
                }
                $profile->{$col} = $request->file($col)->store("winning-products/{$id}", 'public');
            }
        }
        $profile->fill([
            'hook' => trim($data['hook']),
            'ad_angle' => trim($data['ad_angle']),
            'creative_tip' => $data['creative_tip'] ?? null,
            'local_caption' => $data['local_caption'] ?? null,
            'original_caption' => $data['original_caption'] ?? null,
            'sort_order' => $data['sort_order'] ?? $profile->sort_order ?? 0,
            'is_winning' => array_key_exists('is_winning', $data) && $data['is_winning'] !== null ? (bool) $data['is_winning'] : true,
            'updated_by' => ($request->attributes->get('api_user') ?? $request->user())?->id,
        ]);
        $profile->save();

        return $this->ok('Winning product saved', $profile->fresh());
    }

    /** DELETE /admin/products/{id}/winning — hide from marketers (content is kept). */
    public function adminUnmark(int $id): JsonResponse
    {
        Profile::where('product_id', $id)->update(['is_winning' => false]);

        return $this->ok('Removed from winning products');
    }

    /* ======================= Marketer ======================= */

    /** GET /marketer/winning-products?search=&starred=1 */
    public function marketerIndex(Request $request): JsonResponse
    {
        $marketerId = $request->user()->id;
        $starred = DB::table('marketer_starred_products')->where('marketer_user_id', $marketerId)->pluck('product_id')->all();

        $q = Profile::query()
            ->where('is_winning', true)
            ->whereHas('product', fn ($p) => $p->where('approved', 1)->whereHas('vendor', fn ($v) => $v->where('is_active', 1)))
            ->with(['product:id,name,sku,admin_price,max_resell_price,thumbnail_img,category_id', 'product.primaryImage:id,file_name,external_link', 'product.category:id,name'])
            ->orderBy('sort_order')->latest('id');
        if ($request->boolean('starred')) {
            $q->whereIn('product_id', $starred ?: [0]);
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $q->where(fn ($w) => $w->where('hook', 'like', "%{$s}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$s}%"))));
        }

        $page = $q->paginate(min(50, max(1, (int) $request->input('per_page', 24))));
        $page->getCollection()->transform(function (Profile $w) use ($starred) {
            $p = $w->product;
            $rb = round((float) $p->admin_price, 2);
            $suggested = $p->max_resell_price && (float) $p->max_resell_price > $rb ? round((float) $p->max_resell_price, 2) : round($rb * 1.4);

            return [
                'product_id' => $p->id,
                'name' => $p->name,
                'category' => $p->category?->name,
                'image' => $p->primaryImage?->external_link ?: $p->primaryImage?->file_name,
                'rb_price' => $rb,
                'suggested_price' => $suggested,
                'client_profit' => round($suggested - $rb, 2),
                'hook' => $w->hook,
                'ad_angle' => $w->ad_angle,
                'creative_tip' => $w->creative_tip,
                'local_image' => $w->local_image,
                'local_caption' => $w->local_caption,
                'original_image' => $w->original_image,
                'original_caption' => $w->original_caption,
                'has_comparison' => (bool) ($w->local_image && $w->original_image),
                'starred' => in_array($p->id, $starred, true),
            ];
        });

        return $this->ok('Winning products', $page);
    }

    private function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $status);
    }
}
