<?php

namespace App\Http\Controllers;

use App\Models\FacebookAccount;
use App\Models\FacebookPage;
use App\Service\FacebookApiException;
use App\Service\FacebookGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One-click Facebook page connect for dropshippers.
 *
 *  1. GET  /facebook/connect/start            → Facebook login link (state = one-time code for this user)
 *  2.      Facebook login → GET /facebook/connect/callback (no app token: Facebook calls it)
 *          → long-lived token, pages read, kept server-side for 15 min → back to the panel with ?connect=<key>
 *  3. GET  /facebook/connect/pending/{key}    → the pages to choose from (no tokens)
 *     POST /facebook/connect/pending/{key}    → save the chosen pages
 *  4. GET  /facebook/my-pages, POST …/{id}/check, DELETE …/{id}
 *  5. POST /facebook/data-deletion            → Meta's data-deletion callback (signed by Meta)
 *
 * Tokens never leave the server: encrypted in the database and in the short-lived cache.
 * The user is always the logged-in one (never a user_id sent by the browser).
 */
class FacebookConnectController extends Controller
{
    private const STATE_MINUTES = 10;
    private const PENDING_MINUTES = 15;

    public function __construct(private readonly FacebookGraphService $graph)
    {
    }

    private function me(Request $request)
    {
        return $request->attributes->get('api_user') ?? $request->user();
    }

    private function ok(string $message, $data = null, int $code = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $code);
    }

    private function fail(string $message, int $code = 422, ?string $reason = null): JsonResponse
    {
        return response()->json(['status' => 'failed', 'message' => $message, 'reason' => $reason], $code);
    }

    /** GET /facebook/connect/start */
    public function start(Request $request): JsonResponse
    {
        if (!$this->graph->isConfigured()) {
            return $this->fail('Facebook connect is not set up yet. Please contact support.', 503, 'not_configured');
        }
        $state = Str::random(48);
        Cache::put("fb-connect-state:{$state}", ['user_id' => $this->me($request)->id], now()->addMinutes(self::STATE_MINUTES));

        return $this->ok('Open this link to log in with Facebook', ['url' => $this->graph->loginUrl($state)]);
    }

    /** GET /facebook/connect/callback — Facebook sends the dropshipper back here. Always redirects to the panel. */
    public function callback(Request $request)
    {
        $state = (string) $request->query('state', '');
        $saved = $state !== '' ? Cache::pull("fb-connect-state:{$state}") : null; // one-time use
        if (!$saved) {
            return $this->backToPanel(['fb_error' => 'expired']);
        }
        if ($request->filled('error') || !$request->filled('code')) {
            return $this->backToPanel(['fb_error' => 'cancelled']);
        }

        try {
            $user = $this->graph->userTokenFromCode((string) $request->query('code'));
            if ($user['token'] === '') {
                return $this->backToPanel(['fb_error' => 'failed']);
            }
            $fbUser = $this->graph->me($user['token']);
            $pages = $this->graph->pages($user['token']);

            $account = FacebookAccount::updateOrCreate(
                ['user_id' => $saved['user_id'], 'fb_user_id' => (string) ($fbUser['id'] ?? '')],
                [
                    'fb_name' => (string) ($fbUser['name'] ?? 'Facebook user'),
                    'access_token' => Crypt::encryptString($user['token']),
                    'token_expires_at' => $user['expires_in'] ? now()->addSeconds($user['expires_in']) : null,
                    'updated_at' => now(),
                ]
            );

            if ($pages === []) {
                return $this->backToPanel(['fb_error' => 'no_pages']);
            }

            $key = Str::random(40);
            Cache::put("fb-connect-pending:{$key}", [
                'user_id' => $saved['user_id'],
                'account_id' => $account->id,
                'pages' => array_map(fn ($p) => ['access_token' => Crypt::encryptString($p['access_token'])] + $p, $pages),
            ], now()->addMinutes(self::PENDING_MINUTES));

            return $this->backToPanel(['connect' => $key]);
        } catch (\Throwable $e) {
            Log::warning('Facebook connect callback failed', ['user_id' => $saved['user_id'], 'error' => $e->getMessage()]);

            return $this->backToPanel(['fb_error' => 'failed']);
        }
    }

    /** GET /facebook/connect/pending/{key} — pages to choose from (no tokens). */
    public function pending(Request $request, string $key): JsonResponse
    {
        $pending = $this->pendingFor($request, $key);
        if (!$pending) {
            return $this->fail('This connection has expired. Please connect again.', 410, 'expired');
        }
        $connected = FacebookPage::where('user_id', $pending['user_id'])
            ->where('status', FacebookPage::STATUS_CONNECTED)->pluck('page_id')->all();

        return $this->ok('Choose the pages to connect', array_map(fn ($p) => [
            'page_id' => $p['page_id'],
            'name' => $p['name'],
            'category' => $p['category'],
            'picture_url' => $p['picture_url'],
            'can_post' => $p['can_post'],
            'already_connected' => in_array($p['page_id'], $connected, true),
        ], $pending['pages']));
    }

    /** POST /facebook/connect/pending/{key}  {page_ids: [...]} */
    public function choose(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['page_ids' => ['required', 'array', 'min:1', 'max:50'], 'page_ids.*' => ['string', 'max:64']]);
        $pending = $this->pendingFor($request, $key);
        if (!$pending) {
            return $this->fail('This connection has expired. Please connect again.', 410, 'expired');
        }
        $chosen = array_values(array_filter($pending['pages'], fn ($p) => in_array($p['page_id'], $data['page_ids'], true)));
        if ($chosen === []) {
            return $this->fail('Choose at least one of your pages.');
        }

        DB::transaction(function () use ($chosen, $pending) {
            foreach ($chosen as $p) {
                FacebookPage::updateOrCreate(
                    ['user_id' => $pending['user_id'], 'page_id' => $p['page_id']],
                    [
                        'facebook_account_id' => $pending['account_id'],
                        'page_name' => $p['name'],
                        'category' => $p['category'] ?: 'Page',
                        'page_access_token' => $p['access_token'], // already encrypted
                        'picture_url' => $p['picture_url'],
                        'status' => FacebookPage::STATUS_CONNECTED,
                        'last_error' => null,
                        'connected_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });
        Cache::forget("fb-connect-pending:{$key}");

        return $this->ok(count($chosen) . ' page(s) connected', $this->pagesOf($pending['user_id']));
    }

    /** GET /facebook/my-pages */
    public function myPages(Request $request): JsonResponse
    {
        return $this->ok('Your Facebook pages', [
            'configured' => $this->graph->isConfigured(),
            'pages' => $this->pagesOf($this->me($request)->id),
        ]);
    }

    /** POST /facebook/my-pages/{id}/check — is the connection still working? */
    public function check(Request $request, int $id): JsonResponse
    {
        $page = $this->ownPage($request, $id);
        if (!$page) {
            return $this->fail('Page not found.', 404);
        }
        $problem = $this->graph->checkPage($page);
        $page->update([
            'status' => $problem ? FacebookPage::STATUS_NEEDS_RECONNECT : FacebookPage::STATUS_CONNECTED,
            'last_error' => $problem ? Str::limit($problem, 480) : null,
            'updated_at' => now(),
        ]);

        return $this->ok($problem ? 'This page needs to be connected again.' : 'Connection is working.', $this->present($page->fresh()));
    }

    /** DELETE /facebook/my-pages/{id} — disconnect: the token is wiped, post history is kept. */
    public function disconnect(Request $request, int $id): JsonResponse
    {
        $page = $this->ownPage($request, $id);
        if (!$page) {
            return $this->fail('Page not found.', 404);
        }
        $page->update(['page_access_token' => '', 'status' => FacebookPage::STATUS_DISCONNECTED, 'last_error' => null, 'updated_at' => now()]);

        return $this->ok('Page disconnected.');
    }

    /**
     * POST /facebook/data-deletion — Meta calls this when a person removes our app and asks for their data
     * to be deleted. The signed_request is verified with the app secret.
     */
    public function dataDeletion(Request $request): JsonResponse
    {
        $payload = $this->parseSignedRequest((string) $request->input('signed_request', ''));
        if (!$payload || empty($payload['user_id'])) {
            return response()->json(['error' => 'Invalid signed request'], 400);
        }
        $fbUserId = (string) $payload['user_id'];
        $code = Str::upper(Str::random(12));
        DB::transaction(function () use ($fbUserId) {
            $accounts = FacebookAccount::where('fb_user_id', $fbUserId)->get();
            FacebookPage::whereIn('facebook_account_id', $accounts->pluck('id'))->update([
                'page_access_token' => '', 'status' => FacebookPage::STATUS_DISCONNECTED, 'updated_at' => now(),
            ]);
            FacebookAccount::whereIn('id', $accounts->pluck('id'))->update(['access_token' => null, 'updated_at' => now()]);
        });
        Log::info('Facebook data deletion request handled', ['confirmation_code' => $code]);

        return response()->json([
            'url' => rtrim((string) config('services.facebook.frontend_return_url'), '/') . '?deletion=' . $code,
            'confirmation_code' => $code,
        ]);
    }

    /* ---------------------------------------------------------------- helpers */

    private function pendingFor(Request $request, string $key): ?array
    {
        $pending = Cache::get("fb-connect-pending:{$key}");

        return $pending && (int) $pending['user_id'] === (int) $this->me($request)->id ? $pending : null;
    }

    private function ownPage(Request $request, int $id): ?FacebookPage
    {
        return FacebookPage::where('id', $id)->where('user_id', $this->me($request)->id)->first();
    }

    private function pagesOf(int $userId): array
    {
        return FacebookPage::where('user_id', $userId)->where('status', '!=', FacebookPage::STATUS_DISCONNECTED)
            ->orderBy('page_name')->get()->map(fn ($p) => $this->present($p))->all();
    }

    private function present(FacebookPage $p): array
    {
        return [
            'id' => $p->id,
            'page_id' => $p->page_id,
            'name' => $p->page_name,
            'category' => $p->category,
            'picture_url' => $p->picture_url,
            'status' => $p->status ?: FacebookPage::STATUS_CONNECTED,
            'last_error' => $p->last_error,
            'connected_at' => $p->connected_at?->toIso8601String(),
        ];
    }

    private function backToPanel(array $query)
    {
        return redirect()->away(rtrim((string) config('services.facebook.frontend_return_url'), '/') . '?' . http_build_query($query));
    }

    private function parseSignedRequest(string $signed): ?array
    {
        if (!str_contains($signed, '.')) {
            return null;
        }
        [$sig, $payload] = explode('.', $signed, 2);
        $decode = fn (string $s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
        $expected = hash_hmac('sha256', $payload, (string) config('services.facebook.app_secret'), true);
        if (!hash_equals($expected, (string) $decode($sig))) {
            return null;
        }
        $data = json_decode((string) $decode($payload), true);

        return is_array($data) ? $data : null;
    }
}
