<?php

namespace App\Http\Controllers;

use App\Models\EcommerceWebsiteLead;
use App\Service\NotificationService;
use Illuminate\Http\Request;

class EcommerceWebsiteLeadController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

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
     * POST /ecommerce-website-leads
     *
     * Collects a lead from the ecommerce-website promo page's three
     * action buttons: "Interested, talk to me later", "I have a question"
     * (with the dropshipper's typed question), and the (currently disabled)
     * "Show packages".
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'lead_type' => ['required', 'string', 'in:' . implode(',', [
                    EcommerceWebsiteLead::TYPE_INTERESTED_LATER,
                    EcommerceWebsiteLead::TYPE_QUESTION,
                    EcommerceWebsiteLead::TYPE_SHOW_PACKAGES,
                ])],
                'message' => ['required_if:lead_type,' . EcommerceWebsiteLead::TYPE_QUESTION, 'nullable', 'string', 'max:2000'],
            ]);

            $user = $request->user();

            $lead = EcommerceWebsiteLead::create([
                'user_id' => $user?->id,
                'lead_type' => $validated['lead_type'],
                'message' => $validated['message'] ?? null,
                'status' => EcommerceWebsiteLead::STATUS_NEW,
            ]);

            $typeLabel = [
                EcommerceWebsiteLead::TYPE_INTERESTED_LATER => 'আগ্রহী (পরে কথা বলবেন)',
                EcommerceWebsiteLead::TYPE_QUESTION => 'প্রশ্ন করেছেন',
                EcommerceWebsiteLead::TYPE_SHOW_PACKAGES => 'প্যাকেজ দেখতে চান',
            ][$validated['lead_type']] ?? $validated['lead_type'];

            $this->notificationService->createAdminNotifications([
                'title' => 'নতুন ইকমার্স ওয়েবসাইট লিড',
                'subtitle' => ($user?->name ?: 'একজন ব্যবহারকারী') . " - {$typeLabel}",
                'created_by' => $user?->id,
                'is_seen' => false,
                'type' => 'ecommerce_lead',
                'is_active' => true,
                'image' => null,
                'module' => 'ecommerce_website_lead',
            ]);

            return $this->success('ধন্যবাদ! আমরা শীঘ্রই যোগাযোগ করব।', $lead);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /ecommerce-website-leads (admin)
     */
    public function index(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $query = EcommerceWebsiteLead::with(['user:id,name,phone,email', 'contactedBy:id,name'])
                ->latest();

            if ($request->filled('status')) {
                $query->where('status', $request->get('status'));
            }
            if ($request->filled('lead_type')) {
                $query->where('lead_type', $request->get('lead_type'));
            }

            $leads = $query->paginate($perPage);

            return $this->success('Ecommerce website leads fetched', $leads);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /ecommerce-website-leads/{id}/status (admin)
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => ['required', 'string', 'in:' . implode(',', [
                    EcommerceWebsiteLead::STATUS_NEW,
                    EcommerceWebsiteLead::STATUS_CONTACTED,
                    EcommerceWebsiteLead::STATUS_CLOSED,
                ])],
                'admin_note' => ['nullable', 'string', 'max:2000'],
            ]);

            $lead = EcommerceWebsiteLead::find($id);
            if (!$lead) {
                return $this->failed('Lead not found', null, 404);
            }

            $updates = [
                'status' => $validated['status'],
                'admin_note' => $validated['admin_note'] ?? $lead->admin_note,
            ];

            if ($validated['status'] === EcommerceWebsiteLead::STATUS_CONTACTED && $lead->status !== EcommerceWebsiteLead::STATUS_CONTACTED) {
                $updates['contacted_by'] = $request->user()?->id;
                $updates['contacted_at'] = now();
            }

            $lead->update($updates);

            return $this->success('Lead updated', $lead);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
