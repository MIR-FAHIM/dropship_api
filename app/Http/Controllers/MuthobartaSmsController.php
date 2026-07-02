<?php

namespace App\Http\Controllers;

use App\Service\MuthobartaSmsService;
use Illuminate\Http\Request;

class MuthobartaSmsController extends Controller
{
    public function sendMessage(Request $request, MuthobartaSmsService $smsService)
    {
        try {
            $validated = $request->validate([
                'receiver' => ['required', 'string', 'max:20'],
                'message' => ['required', 'string', 'max:500'],
                'type' => ['nullable', 'string', 'max:50'],
                'remove_duplicate' => ['nullable', 'boolean'],
            ]);

            $result = $smsService->send(
                $validated['receiver'],
                $validated['message'],
                $validated['type'] ?? 'manual_test',
                $validated['remove_duplicate'] ?? true
            );

            if (!$result['success']) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'SMS provider request failed',
                    'errors' => $result['body'],
                ], $result['status'] ?: 500);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'SMS sent successfully',
                'data' => $result['body'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Could not send SMS',
                'errors' => $e->getMessage(),
            ], 500);
        }
    }
}
