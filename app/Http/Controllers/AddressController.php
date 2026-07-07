<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use App\Models\Division;
use App\Models\District;
use App\Models\Upazila;

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
	 * GET /districts/{id}
	 * Returns all districts for a specific division
	 */
	public function getDistrictList($id)
	{
		try {
			$query = District::query();
			if ($id) {
				$query->where('division_id', $id);
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

	/**
	 * GET /upazilas/{districtId}
	 * Returns all upazilas for a specific district
	 */
	public function getUpazilasByDistrict($districtId)
	{
		try {
			$query = Upazila::query();
			if ($districtId) {
				$query->where('district_id', $districtId);
			}
			$upazilas = $query->get();

			return response()->json([
				'status' => 'success',
				'message' => 'Upazilas fetched successfully',
				'data' => $upazilas
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
