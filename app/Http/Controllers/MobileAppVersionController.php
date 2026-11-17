<?php

namespace App\Http\Controllers;

use App\Models\MobileAppVersionUpdate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mobile app update system.
 *
 * Public:  GET  app-version/check?platform=android&build=11
 * Admin:   GET  app-version/list, POST app-version/store,
 *          POST app-version/update/{id}, POST app-version/toggle/{id}
 */
class MobileAppVersionController extends Controller
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

    /**
     * Tells the app whether it must, may or need not update.
     * update_type: none | optional | force
     */
    public function check(Request $request)
    {
        try {
            $validated = $request->validate([
                'platform' => ['required', Rule::in(MobileAppVersionUpdate::PLATFORMS)],
                'build' => ['required', 'integer', 'min:0'],
            ]);
            $build = (int) $validated['build'];

            $latest = MobileAppVersionUpdate::activeFor($validated['platform'])
                ->orderByDesc('build_number')
                ->first();

            if (!$latest || $build >= $latest->build_number) {
                return $this->success('App is up to date', [
                    'update_type' => 'none',
                    'current_build' => $build,
                    'latest_build' => $latest?->build_number,
                    'latest_version' => $latest?->version_name,
                ]);
            }

            // Forced when this build is below the highest minimum of any active
            // release, or when any newer active release is marked forced, so a
            // forced release can't be skipped by publishing an optional one after it.
            $newer = MobileAppVersionUpdate::activeFor($validated['platform'])
                ->where('build_number', '>', $build);
            $minSupported = (int) (clone $newer)->max('min_supported_build');
            $force = $build < $minSupported || (clone $newer)->where('is_force_update', true)->exists();

            return $this->success('Update available', [
                'update_type' => $force ? 'force' : 'optional',
                'current_build' => $build,
                'latest_build' => $latest->build_number,
                'latest_version' => $latest->version_name,
                'min_supported_build' => $minSupported,
                'title' => $latest->title,
                'release_notes' => $latest->release_notes,
                'store_url' => $latest->store_url,
                'released_at' => $latest->released_at,
            ]);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        $query = MobileAppVersionUpdate::query()->orderByDesc('build_number');
        if ($platform = $request->query('platform')) {
            $query->where('platform', $platform);
        }
        return $this->success('App versions fetched', $query->get());
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate($this->rules());
            $data['created_by'] = $this->userId($request);
            $data['released_at'] = $data['released_at'] ?? now();
            $version = MobileAppVersionUpdate::create($data);
            return $this->success('App version created', $version, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $version = MobileAppVersionUpdate::find($id);
            if (!$version) {
                return $this->failed('App version not found', null, 404);
            }
            $version->update($request->validate($this->rules($version->id, true)));
            return $this->success('App version updated', $version->fresh());
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function toggle($id)
    {
        $version = MobileAppVersionUpdate::find($id);
        if (!$version) {
            return $this->failed('App version not found', null, 404);
        }
        $version->update(['is_active' => !$version->is_active]);
        return $this->success($version->is_active ? 'Version activated' : 'Version deactivated', $version);
    }

    private function rules(?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $platform = request('platform');
        if ($platform === null && $ignoreId) {
            $platform = MobileAppVersionUpdate::whereKey($ignoreId)->value('platform');
        }

        return [
            'platform' => [$required, Rule::in(MobileAppVersionUpdate::PLATFORMS)],
            'version_name' => [$required, 'string', 'max:30'],
            'build_number' => [
                $required, 'integer', 'min:1',
                Rule::unique('mobile_app_version_updates', 'build_number')
                    ->where('platform', $platform)
                    ->ignore($ignoreId),
            ],
            'min_supported_build' => ['sometimes', 'integer', 'min:0'],
            'is_force_update' => ['sometimes', 'boolean'],
            'title' => ['nullable', 'string', 'max:150'],
            'release_notes' => ['nullable', 'string', 'max:5000'],
            'store_url' => [$required, 'url', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'released_at' => ['nullable', 'date'],
        ];
    }

    private function userId(Request $request): ?int
    {
        $user = $request->attributes->get('api_user') ?? $request->user();
        return $user?->id;
    }
}
