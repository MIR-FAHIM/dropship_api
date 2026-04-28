<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use App\Models\Division;
use App\Models\District;

class AddressController extends Controller
{
	/**
	 * GET /divisions
	 * Returns all divisions
	 */
	public function divisionsList(Request $request)
	{
		try {
			$divisions = Division::all();
			return response()->json([
				'status' => 'success',
				'message' => 'Divisions fetched successfully',
				'data' => $divisions
			]);
		} catch (\Throwable $e) {
			return response()->json([
				'status' => 'failed',
				'message' => 'Something went wrong',
				'errors' => ['error' => $e->getMessage()]
			], 500);
		}
	}

	/**
	 * GET /districts?division_id=1
	 * Returns all districts, optionally filtered by division_id
	 */
	public function getDistrictList(Request $request)
	{
		try {
			$query = District::query();
			if ($request->filled('division_id')) {
				$query->where('division_id', $request->division_id);
			}
			$districts = $query->get();
			return response()->json([
				'status' => 'success',
				'message' => 'Districts fetched successfully',
				'data' => $districts
			]);
		} catch (\Throwable $e) {
			return response()->json([
				'status' => 'failed',
				'message' => 'Something went wrong',
				'errors' => ['error' => $e->getMessage()]
			], 500);
		}
	}
}
