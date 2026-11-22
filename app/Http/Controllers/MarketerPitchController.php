<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Partner Portal F5 — data for the Pitch Calculator (the maths runs in the browser).
 * Marketer logins may only call marketer.panel.* routes, so they get their own product search.
 * Only the Reseller Brain (admin) price and the max resell price are shared — never the
 * supplier's price.
 */
class MarketerPitchController extends Controller
{
    private const DEFAULT_DOLLAR_RATE = 125;

    /** GET /marketer/pitch/products?search=&starred=1&page= */
    public function products(Request $request): JsonResponse
    {
        $marketerId = $request->user()->id;
        $starredIds = DB::table('marketer_starred_products')->where('marketer_user_id', $marketerId)->pluck('product_id')->all();

        $q = Product::query()
            ->with(['primaryImage:id,file_name,external_link', 'category:id,name'])
            ->where('approved', 1)
            ->whereHas('vendor', fn ($v) => $v->where('is_active', 1))
            ->whereNotNull('admin_price')->where('admin_price', '>', 0)
            ->select(['id', 'name', 'sku', 'admin_price', 'max_resell_price', 'thumbnail_img', 'category_id']);

        if ($request->boolean('starred')) {
            $q->whereIn('id', $starredIds ?: [0]);
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$s}%")));
        }
        // Starred first, then newest.
        if ($starredIds) {
            $q->orderByRaw('CASE WHEN id IN (' . implode(',', array_map('intval', $starredIds)) . ') THEN 0 ELSE 1 END');
        }
        $page = $q->latest('id')->paginate(min(50, max(1, (int) $request->input('per_page', 20))));

        $page->getCollection()->transform(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'category' => $p->category?->name,
            'rb_price' => round((float) $p->admin_price, 2),
            'suggested_price' => $p->max_resell_price ? round((float) $p->max_resell_price, 2) : null,
            'image' => $p->primaryImage?->external_link ?: $p->primaryImage?->file_name,
            'starred' => in_array($p->id, $starredIds, true),
        ]);

        return $this->ok('Pitch products', $page);
    }

    /** POST /marketer/pitch/products/{id}/star */
    public function star(Request $request, int $id): JsonResponse
    {
        if (!Product::where('approved', 1)->whereKey($id)->exists()) {
            return response()->json(['status' => 'failed', 'message' => 'Product not found', 'errors' => null], 404);
        }
        DB::table('marketer_starred_products')->insertOrIgnore([
            'marketer_user_id' => $request->user()->id, 'product_id' => $id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->ok('Starred', ['product_id' => $id, 'starred' => true]);
    }

    /** DELETE /marketer/pitch/products/{id}/star */
    public function unstar(Request $request, int $id): JsonResponse
    {
        DB::table('marketer_starred_products')->where('marketer_user_id', $request->user()->id)->where('product_id', $id)->delete();

        return $this->ok('Unstarred', ['product_id' => $id, 'starred' => false]);
    }

    /** GET /marketer/pitch/settings */
    public function settings(Request $request): JsonResponse
    {
        $rate = $request->user()->marketer_dollar_rate;

        return $this->ok('Pitch settings', [
            'dollar_rate' => $rate !== null ? (float) $rate : self::DEFAULT_DOLLAR_RATE,
            'dollar_rate_saved' => $rate !== null,
            'starred_count' => DB::table('marketer_starred_products')->where('marketer_user_id', $request->user()->id)->count(),
        ]);
    }

    /** PUT /marketer/pitch/settings {dollar_rate} */
    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['dollar_rate' => ['required', 'numeric', 'min:50', 'max:500']]);
        $request->user()->forceFill(['marketer_dollar_rate' => round((float) $data['dollar_rate'], 2)])->save();

        return $this->ok('Dollar rate saved', ['dollar_rate' => round((float) $data['dollar_rate'], 2), 'dollar_rate_saved' => true]);
    }

    private function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $status);
    }
}
