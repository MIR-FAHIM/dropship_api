<?php

namespace App\Service;

use App\Models\FacebookPage;
use Illuminate\Support\Facades\Http;

/**
 * All calls to Facebook's Graph API for dropshipper page connect and posting.
 * Every call that uses a token is signed with appsecret_proof (HMAC of the token with the app secret),
 * so a leaked token alone can't be used against our app.
 */
class FacebookGraphService
{
    /** Graph error codes that mean the token no longer works and the page must be reconnected. */
    public const RECONNECT_CODES = [190, 102, 10, 200];

    public function isConfigured(): bool
    {
        return (string) config('services.facebook.app_id') !== '' && (string) config('services.facebook.app_secret') !== '';
    }

    private function base(): string
    {
        return 'https://graph.facebook.com/' . config('services.facebook.graph_version', 'v21.0');
    }

    public function proof(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('services.facebook.app_secret'));
    }

    /** The Facebook login link (state ties the return trip to one logged-in dropshipper). */
    public function loginUrl(string $state): string
    {
        $params = [
            'client_id' => config('services.facebook.app_id'),
            'redirect_uri' => config('services.facebook.redirect_uri'),
            'state' => $state,
            'response_type' => 'code',
        ];
        if ($configId = config('services.facebook.config_id')) {
            $params['config_id'] = $configId;
        } else {
            $params['scope'] = implode(',', (array) config('services.facebook.scopes', []));
        }

        return 'https://www.facebook.com/' . config('services.facebook.graph_version', 'v21.0') . '/dialog/oauth?' . http_build_query($params);
    }

    /** Login code → short-lived user token → long-lived user token (about 60 days). */
    public function userTokenFromCode(string $code): array
    {
        $short = $this->get('/oauth/access_token', [
            'client_id' => config('services.facebook.app_id'),
            'client_secret' => config('services.facebook.app_secret'),
            'redirect_uri' => config('services.facebook.redirect_uri'),
            'code' => $code,
        ]);
        $long = $this->get('/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.app_id'),
            'client_secret' => config('services.facebook.app_secret'),
            'fb_exchange_token' => $short['access_token'] ?? '',
        ]);

        return [
            'token' => (string) ($long['access_token'] ?? $short['access_token'] ?? ''),
            'expires_in' => isset($long['expires_in']) ? (int) $long['expires_in'] : null,
        ];
    }

    /** The Facebook user behind a token: ['id' => …, 'name' => …]. */
    public function me(string $userToken): array
    {
        return $this->get('/me', ['fields' => 'id,name'], $userToken);
    }

    /**
     * Pages the user manages. Page tokens made from a long-lived user token don't expire.
     * Only pages where the user can create content are useful for posting.
     */
    public function pages(string $userToken): array
    {
        $res = $this->get('/me/accounts', ['fields' => 'id,name,category,access_token,tasks,picture{url}', 'limit' => 100], $userToken);

        return collect($res['data'] ?? [])->map(fn ($p) => [
            'page_id' => (string) ($p['id'] ?? ''),
            'name' => (string) ($p['name'] ?? ''),
            'category' => (string) ($p['category'] ?? ''),
            'access_token' => (string) ($p['access_token'] ?? ''),
            'picture_url' => $p['picture']['data']['url'] ?? null,
            'can_post' => in_array('CREATE_CONTENT', (array) ($p['tasks'] ?? []), true) || in_array('MANAGE', (array) ($p['tasks'] ?? []), true),
        ])->filter(fn ($p) => $p['page_id'] !== '' && $p['access_token'] !== '')->values()->all();
    }

    /** Checks a saved page still works. Returns null when fine, else a short reason. */
    public function checkPage(FacebookPage $page): ?string
    {
        $token = $page->plainToken();
        if (!$token) {
            return 'No page token saved.';
        }
        try {
            $this->verifyPage($page);

            return null;
        } catch (FacebookApiException $e) {
            return $e->getMessage();
        }
    }

    /** Throws FacebookApiException (with Facebook's error code) when the page token no longer works. */
    public function verifyPage(FacebookPage $page): void
    {
        $token = $page->plainToken();
        if (!$token) {
            throw new FacebookApiException('No page token saved.', 190);
        }
        $this->get('/' . $page->page_id, ['fields' => 'id,name'], $token);
    }

    /** Post a photo (with caption) or a text post to a page. Returns Facebook's post id. */
    public function publish(FacebookPage $page, string $message, ?string $imageUrl = null): string
    {
        $token = $page->plainToken();
        if (!$token) {
            throw new FacebookApiException('This page is not connected.', 190);
        }
        $res = $imageUrl
            ? $this->post('/' . $page->page_id . '/photos', ['url' => $imageUrl, 'caption' => $message], $token)
            : $this->post('/' . $page->page_id . '/feed', ['message' => $message], $token);

        return (string) ($res['post_id'] ?? $res['id'] ?? '');
    }

    /**
     * One post with a caption and 0–10 photos, now or scheduled. Photos are uploaded to the page
     * unpublished first, then attached to a single feed post (so 3 photos = 1 post, not 3).
     * Returns Facebook's post id.
     */
    public function publishPost(FacebookPage $page, string $message, array $imageUrls = [], ?\DateTimeInterface $scheduleAt = null): string
    {
        $token = $page->plainToken();
        if (!$token) {
            throw new FacebookApiException('This page is not connected.', 190);
        }
        $schedule = $scheduleAt ? ['published' => 'false', 'scheduled_publish_time' => $scheduleAt->getTimestamp()] : [];

        $form = ['message' => $message] + $schedule;
        foreach (array_values(array_slice($imageUrls, 0, 10)) as $i => $url) {
            $photo = $this->post('/' . $page->page_id . '/photos',
                ['url' => $url, 'published' => 'false'] + ($scheduleAt ? ['temporary' => 'true'] : []), $token);
            $form["attached_media[{$i}]"] = json_encode(['media_fbid' => (string) ($photo['id'] ?? '')]);
        }
        $res = $this->post('/' . $page->page_id . '/feed', $form, $token);

        return (string) ($res['id'] ?? $res['post_id'] ?? '');
    }

    /**
     * Likes (all reactions), comments, shares and the link of a post; whether it is live yet
     * (a scheduled post becomes is_published = true at its time).
     */
    public function postStats(FacebookPage $page, string $fbPostId): array
    {
        $token = $page->plainToken();
        if (!$token) {
            throw new FacebookApiException('This page is not connected.', 190);
        }
        $res = $this->get('/' . $fbPostId, [
            'fields' => 'is_published,permalink_url,created_time,shares,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)',
        ], $token);

        return [
            'is_published' => (bool) ($res['is_published'] ?? true),
            'permalink' => $res['permalink_url'] ?? null,
            'likes' => (int) ($res['reactions']['summary']['total_count'] ?? 0),
            'comments' => (int) ($res['comments']['summary']['total_count'] ?? 0),
            'shares' => (int) ($res['shares']['count'] ?? 0),
        ];
    }

    /** Delete a post (used to cancel a scheduled one before it goes live). */
    public function deletePost(FacebookPage $page, string $fbPostId): void
    {
        $token = $page->plainToken();
        if (!$token) {
            throw new FacebookApiException('This page is not connected.', 190);
        }
        $this->decode(Http::timeout(20)->asForm()->delete($this->base() . '/' . $fbPostId, [
            'access_token' => $token, 'appsecret_proof' => $this->proof($token),
        ]));
    }

    /* ---------------------------------------------------------------- http */

    private function get(string $path, array $query, ?string $token = null): array
    {
        if ($token) {
            $query += ['access_token' => $token, 'appsecret_proof' => $this->proof($token)];
        }

        return $this->decode(Http::timeout(20)->get($this->base() . $path, $query));
    }

    private function post(string $path, array $form, string $token): array
    {
        $form += ['access_token' => $token, 'appsecret_proof' => $this->proof($token)];

        return $this->decode(Http::timeout(30)->asForm()->post($this->base() . $path, $form));
    }

    private function decode($response): array
    {
        $body = $response->json();
        if ($response->failed() || isset($body['error'])) {
            $err = is_array($body['error'] ?? null) ? $body['error'] : [];
            throw new FacebookApiException(
                (string) ($err['error_user_msg'] ?? $err['message'] ?? 'Facebook did not accept the request.'),
                (int) ($err['code'] ?? $response->status())
            );
        }

        return is_array($body) ? $body : [];
    }
}
