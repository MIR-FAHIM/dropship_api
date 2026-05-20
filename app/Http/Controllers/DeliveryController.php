<?php

namespace App\Http\Controllers;

use App\Models\AssignDeliveryMan;
use App\Models\District;
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
                 ->whereHas('order', function ($q) {
        $q->whereNotIn('status', ['delivered', 'completed']);
    })
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

    /**
     * GET /deliveries/delivery-charge?vendor_district_id=1&customer_district_id=5
     *
     * Zone rules:
     *   Vendor in Dhaka district  AND  customer in Dhaka district  => Inside Dhaka  (100)
     *   Customer in Dhaka division but NOT Dhaka district           => Outside Dhaka (150)
     *   Customer in Chittagong / Mymensingh division                => 120
     *   Customer in Sylhet / Rajshahi / Khulna / Barishal division  => 130
     *   Customer in Rangpur division                                => 140
     */
    public function calculateDeliveryCharge(Request $request)
    {
        $request->validate([
            'vendor_district_id'   => ['required', 'integer', 'exists:districts,id'],
            'customer_district_id' => ['required', 'integer', 'exists:districts,id'],
        ]);

        try {
            $vendorDistrict   = District::with('division')->find($request->vendor_district_id);
            $customerDistrict = District::with('division')->find($request->customer_district_id);

            $vendorDivision   = strtolower($vendorDistrict->division?->name ?? '');
            $vendorDistName   = strtolower($vendorDistrict->name ?? '');

            $customerDivision = strtolower($customerDistrict->division?->name ?? '');
            $customerDistName = strtolower($customerDistrict->name ?? '');

            $zoneCharges = [
                'inside dhaka'  => 100,
                'outside dhaka' => 150,
                'chittagong'    => 120,
                'mymensingh'    => 120,
                'sylhet'        => 130,
                'rajshahi'      => 130,
                'khulna'        => 130,
                'barishal'      => 130,
                'rangpur'       => 140,
            ];

            if (str_contains($customerDivision, 'dhaka')) {
                // Inside Dhaka: both vendor and customer are in Dhaka district
                if (
                    str_contains($vendorDivision, 'dhaka') &&
                    str_contains($vendorDistName, 'dhaka') &&
                    str_contains($customerDistName, 'dhaka')
                ) {
                    $zone   = 'Inside Dhaka';
                    $charge = $zoneCharges['inside dhaka'];
                } else {
                    $zone   = 'Outside Dhaka';
                    $charge = $zoneCharges['outside dhaka'];
                }
            } else {
                $matchedCharge = null;
                $matchedZone   = null;
                foreach ($zoneCharges as $key => $value) {
                    if (str_contains($customerDivision, $key)) {
                        $matchedCharge = $value;
                        $matchedZone   = ucfirst($key);
                        break;
                    }
                }
                $charge = $matchedCharge ?? 150;
                $zone   = $matchedZone ?? 'Outside Dhaka';
            }

            return $this->success('Delivery charge calculated', [
                'vendor_district_id'     => $vendorDistrict->id,
                'vendor_district_name'   => $vendorDistrict->name,
                'vendor_division_name'   => $vendorDistrict->division?->name,
                'customer_district_id'   => $customerDistrict->id,
                'customer_district_name' => $customerDistrict->name,
                'customer_division_name' => $customerDistrict->division?->name,
                'zone'                   => $zone,
                'charge'                 => $charge,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
