<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SupportTicket;

class SupportTicketController extends Controller
{
    public function addSupportTicket(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id'      => 'required|exists:users,id',
                'order_id'     => 'nullable|exists:orders,id',
                'support_type' => 'required|string|max:100',
                'title'        => 'required|string|max:255',
                'description'  => 'required|string',
                'image_id'     => 'nullable|exists:uploads,id',
            ]);

            $ticket = SupportTicket::create([
                'user_id'      => $validated['user_id'],
                'order_id'     => $validated['order_id'] ?? null,
                'support_type' => $validated['support_type'],
                'title'        => $validated['title'],
                'description'  => $validated['description'],
                'image_id'     => $validated['image_id'] ?? null,
                'status'       => 'open',
                'is_active'    => true,
            ]);

            return response()->json(['success' => true, 'data' => $ticket], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getAllSupportTicket()
    {
        try {
            $tickets = SupportTicket::with(['user', 'image'])->orderByDesc('id')->get();
            return response()->json(['success' => true, 'data' => $tickets]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getUserSupportTicket($userId)
    {
        try {
            $tickets = SupportTicket::with(['image'])
                ->where('user_id', $userId)
                ->orderByDesc('id')
                ->get();
            return response()->json(['success' => true, 'data' => $tickets]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function editTicket(Request $request, $id)
    {
        try {
            $ticket = SupportTicket::findOrFail($id);

            $validated = $request->validate([
                'order_id'     => 'nullable|exists:orders,id',
                'support_type' => 'sometimes|string|max:100',
                'title'        => 'sometimes|string|max:255',
                'description'  => 'sometimes|string',
                'image_id'     => 'nullable|exists:uploads,id',
                'admin_note'   => 'nullable|string',
            ]);

            $ticket->update($validated);

            return response()->json(['success' => true, 'data' => $ticket->fresh(['user', 'image'])]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function changeTicketStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status'     => 'required|string',
                'admin_note' => 'nullable|string',
            ]);

            $ticket = SupportTicket::findOrFail($id);
            $ticket->status = $validated['status'];
            if (isset($validated['admin_note'])) {
                $ticket->admin_note = $validated['admin_note'];
            }
            $ticket->save();

            return response()->json(['success' => true, 'data' => $ticket]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}

