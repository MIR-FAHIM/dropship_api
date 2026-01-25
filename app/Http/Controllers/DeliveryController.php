<?php

namespace App\Http\Controllers;

use App\Models\AssignDeliveryMan;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors
        ], $code);
    }

    /**
     * POST /deliveries/assign
     * Body: delivery_man_id, order_id, note(optional)
     */
    public function assignDeliveryMan(Request $request)
    {
        try {
            $validated = $request->validate([
                'delivery_man_id' => ['required', 'integer', 'exists:users,id'],
                'order_id' => ['required', 'integer', 'exists:orders,id'],
                'note' => ['nullable', 'string'],
            ]);

            $deliveryMan = User::find($validated['delivery_man_id']);
            if (!$deliveryMan || $deliveryMan->user_type !== 'delivery_boy') {
                return $this->failed('User is not a delivery man', null, 422);
            }

            $order = Order::find($validated['order_id']);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            $existing = AssignDeliveryMan::where('order_id', $validated['order_id'])
                ->where('status', 'assigned')
                ->latest()
                ->first();

            if ($existing) {
                if ((int) $existing->delivery_man_id === (int) $validated['delivery_man_id']) {
                    return $this->success('Delivery man already assigned', $existing->load(['deliveryMan', 'order']));
                }

                $existing->delivery_man_id = $validated['delivery_man_id'];
                $existing->status = 'assigned';
                if (array_key_exists('note', $validated)) {
                    $existing->note = $validated['note'];
                }
                $existing->save();

                return $this->success('Delivery man re-assigned successfully', $existing->load(['deliveryMan', 'order']));
            }

            $assignment = AssignDeliveryMan::create([
                'delivery_man_id' => $validated['delivery_man_id'],
                'order_id' => $validated['order_id'],
                'status' => 'assigned',
                'note' => $validated['note'] ?? null,
            ]);

            Order::where('id', $validated['order_id'])->update(['status' => 'assigned deliveryman']);

            return $this->success('Delivery man assigned successfully', $assignment->load(['deliveryMan', 'order']), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /deliveries/unassign
     * Body: order_id, delivery_man_id(optional), note(optional)
     */
    public function unassignDeliveryMan(Request $request)
    {
        try {
            $validated = $request->validate([
                'order_id' => ['required', 'integer', 'exists:orders,id'],
                'delivery_man_id' => ['nullable', 'integer', 'exists:users,id'],
                'note' => ['nullable', 'string'],
            ]);

            $query = AssignDeliveryMan::where('order_id', $validated['order_id'])
                ->where('status', 'assigned');

            if (!empty($validated['delivery_man_id'])) {
                $query->where('delivery_man_id', $validated['delivery_man_id']);
            }

            $assignment = $query->latest()->first();

            if (!$assignment) {
                return $this->failed('Assigned delivery man not found for this order', null, 404);
            }

            $assignment->status = 'unassigned';
            if (array_key_exists('note', $validated)) {
                $assignment->note = $validated['note'];
            }
            $assignment->save();

            return $this->success('Delivery man unassigned successfully', $assignment->load(['deliveryMan', 'order']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /deliveries/all/{deliveryManId}?per_page=20
     */
    public function getAllOrderByDeliveryMan($deliveryManId, Request $request)
    {
        try {
            $deliveryMan = User::find($deliveryManId);
            if (!$deliveryMan || $deliveryMan->user_type !== 'delivery_boy') {
                return $this->failed('User is not a delivery man', null, 422);
            }

            $perPage = (int) $request->get('per_page', 20);

            $assignments = AssignDeliveryMan::with(['order', 'deliveryMan'])
                ->where('delivery_man_id', $deliveryManId)
                ->where('status', 'assigned')
                ->latest()
                ->paginate($perPage);

            return $this->success('Deliveries fetched successfully', $assignments);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /deliveries/assigned/{deliveryManId}?per_page=20
     */
    public function getAssignedDelivery($deliveryManId, Request $request)
    {
        try {
            $deliveryMan = User::find($deliveryManId);
            if (!$deliveryMan || $deliveryMan->user_type !== 'delivery_boy') {
                return $this->failed('User is not a delivery man', null, 422);
            }

            $perPage = (int) $request->get('per_page', 20);

            $assignments = AssignDeliveryMan::with(['order', 'deliveryMan'])
                ->where('delivery_man_id', $deliveryManId)
                ->where('status', 'assigned')
                ->latest()
                ->paginate($perPage);

            return $this->success('Assigned deliveries fetched successfully', $assignments);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /deliveries/completed/{deliveryManId}?per_page=20
     */
    public function getCompletedDelivery($deliveryManId, Request $request)
    {
        try {
            $deliveryMan = User::find($deliveryManId);
            if (!$deliveryMan || $deliveryMan->user_type !== 'delivery_boy') {
                return $this->failed('User is not a delivery man', null, 422);
            }

            $perPage = (int) $request->get('per_page', 20);

            $assignments = AssignDeliveryMan::with(['order', 'deliveryMan'])
                ->where('delivery_man_id', $deliveryManId)
                ->where('status', 'assigned')
                ->whereHas('order', function ($q) {
                    $q->where('status', 'completed');
                })
                ->latest()
                ->paginate($perPage);

            return $this->success('Completed deliveries fetched successfully', $assignments);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }


    
}
