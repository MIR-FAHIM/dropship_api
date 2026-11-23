<?php

namespace App\Http\Controllers;

use App\Service\AdminMoneyBoardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /reports/money-board?refresh=1 — admin dashboard: who owes whom + admin's final income. */
class AdminMoneyBoardController extends Controller
{
    public function index(Request $request, AdminMoneyBoardService $board): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'message' => 'Money board', 'data' => $board->cached($request->boolean('refresh'))]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'failed', 'message' => 'Something went wrong', 'errors' => ['error' => $e->getMessage()]], 500);
        }
    }
}
