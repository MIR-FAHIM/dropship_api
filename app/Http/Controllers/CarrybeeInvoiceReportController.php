<?php

namespace App\Http\Controllers;

use App\Service\CarrybeeInvoiceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * GET /delivery-companies/carrybee/all-invoices?from=&to=&receivable_status=&refresh=1
 * Every CarryBee invoice of the platform account and of each vendor's own account,
 * grouped per account with sums and a grand total. See CarrybeeInvoiceReportService.
 */
class CarrybeeInvoiceReportController extends Controller
{
    public function index(Request $request, CarrybeeInvoiceReportService $report): JsonResponse
    {
        try {
            $data = $request->validate([
                'from' => ['nullable', 'date_format:Y-m-d'],
                'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
                'receivable_status' => ['nullable', 'in:all,paid,unpaid,received'],
                'refresh' => ['nullable', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'failed', 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        try {
            return response()->json(['status' => 'success', 'message' => 'CarryBee invoices', 'data' => $report->report([
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
                'receivable_status' => $data['receivable_status'] ?? null,
                'refresh' => $request->boolean('refresh'),
            ])]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'failed', 'message' => 'Something went wrong', 'errors' => ['error' => $e->getMessage()]], 500);
        }
    }
}
