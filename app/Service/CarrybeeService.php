<?php

namespace App\Service;

use Illuminate\Support\Facades\Http;

class CarrybeeService
{
    private const BASE_URL = 'https://sandbox.carrybee.com/api/v2';

    private string $clientId;
    private string $clientSecret;
    private string $clientContext;

    public function __construct(string $clientId, string $clientSecret, string $clientContext)
    {
        $this->clientId      = $clientId;
        $this->clientSecret  = $clientSecret;
        $this->clientContext = $clientContext;
    }

    private function authHeaders(): array
    {
        return [
            'Client-ID'      => $this->clientId,
            'Client-Secret'  => $this->clientSecret,
            'Client-Context' => $this->clientContext,
            'Accept'         => 'application/json',
        ];
    }

    /**
     * GET /cities  (no auth required)
     */
    public static function getCities(): array
    {
        $response = Http::withHeaders(['Accept' => 'application/json'])
            ->get(self::BASE_URL . '/cities');

        return [
            'status' => $response->status(),
            'body'   => $response->json(),
        ];
    }

    /**
     * GET /cities/{cityId}/zones
     */
    public function getZones(int $cityId): array
    {
        $response = Http::withHeaders($this->authHeaders())
            ->get(self::BASE_URL . "/cities/{$cityId}/zones");

        return [
            'status' => $response->status(),
            'body'   => $response->json(),
        ];
    }

    /**
     * GET /cities/{cityId}/zones/{zoneId}/areas
     */
    public function getAreas(int $cityId, int $zoneId): array
    {
        $response = Http::withHeaders($this->authHeaders())
            ->get(self::BASE_URL . "/cities/{$cityId}/zones/{$zoneId}/areas");

        return [
            'status' => $response->status(),
            'body'   => $response->json(),
        ];
    }

    /**
     * GET /area-suggestion?search=
     */
    public function areaSuggestion(string $search): array
    {
        $response = Http::withHeaders($this->authHeaders())
            ->get(self::BASE_URL . '/area-suggestion', ['search' => $search]);

        return [
            'status' => $response->status(),
            'body'   => $response->json(),
        ];
    }

    /**
     * GET /stores
     */
    public function getStores(): array
    {
        $response = Http::withHeaders($this->authHeaders())
            ->get(self::BASE_URL . '/stores');

        return [
            'status' => $response->status(),
            'body'   => $response->json(),
        ];
    }

    /**
     * POST /stores
     */
    public function createStore(array $data): array
    {
        $response = Http::withHeaders($this->authHeaders())
            ->post(self::BASE_URL . '/stores', $data);

        return [
            'status' => $response->status(),
            'body'   => $response->json(),
        ];
    }
}
