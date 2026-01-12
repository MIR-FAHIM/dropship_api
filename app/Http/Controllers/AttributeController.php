<?php

namespace App\Http\Controllers;

use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Http\Request;

class AttributeController extends Controller
{
    /**
     * Add a new attribute
     */
    public function addAttribute(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:attributes',
                'status' => 'required|boolean',
            ]);

            $attribute = Attribute::create($validated);

            return response()->json([
                'status' => true,
                'message' => 'Attribute created successfully',
                'data' => $attribute,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create attribute',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all attributes
     */
    public function getAttributes()
    {
        try {
            $attributes = Attribute::all();

            return response()->json([
                'status' => true,
                'message' => 'Attributes retrieved successfully',
                'data' => $attributes,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve attributes',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get attribute by ID with its values
     */
    public function getAttributeWithValues($id)
    {
        try {
            $attribute = Attribute::with('values')->find($id);

            if (!$attribute) {
                return response()->json([
                    'status' => false,
                    'message' => 'Attribute not found',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Attribute retrieved successfully',
                'data' => $attribute,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve attribute',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an attribute
     */
    public function updateAttribute(Request $request, $id)
    {
        try {
            $attribute = Attribute::find($id);

            if (!$attribute) {
                return response()->json([
                    'status' => false,
                    'message' => 'Attribute not found',
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255|unique:attributes,name,' . $id,
                'status' => 'sometimes|boolean',
            ]);

            $attribute->update($validated);

            return response()->json([
                'status' => true,
                'message' => 'Attribute updated successfully',
                'data' => $attribute,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update attribute',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an attribute
     */
    public function deleteAttribute($id)
    {
        try {
            $attribute = Attribute::find($id);

            if (!$attribute) {
                return response()->json([
                    'status' => false,
                    'message' => 'Attribute not found',
                ], 404);
            }

            $attribute->delete();

            return response()->json([
                'status' => true,
                'message' => 'Attribute deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete attribute',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a new attribute value
     */
    public function addAttributeValue(Request $request)
    {
        try {
            $validated = $request->validate([
                'attribute_id' => 'required|integer|exists:attributes,id',
                'value' => 'required|string|max:255',
                'color_code' => 'nullable|string|max:7',
                'status' => 'required|boolean',
            ]);

            $attributeValue = AttributeValue::create($validated);

            return response()->json([
                'status' => true,
                'message' => 'Attribute value created successfully',
                'data' => $attributeValue,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create attribute value',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an attribute value
     */
    public function updateAttributeValue(Request $request, $id)
    {
        try {
            $attributeValue = AttributeValue::find($id);

            if (!$attributeValue) {
                return response()->json([
                    'status' => false,
                    'message' => 'Attribute value not found',
                ], 404);
            }

            $validated = $request->validate([
                'value' => 'sometimes|string|max:255',
                'color_code' => 'nullable|string|max:7',
                'status' => 'sometimes|boolean',
            ]);

            $attributeValue->update($validated);

            return response()->json([
                'status' => true,
                'message' => 'Attribute value updated successfully',
                'data' => $attributeValue,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update attribute value',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an attribute value
     */
    public function deleteAttributeValue($id)
    {
        try {
            $attributeValue = AttributeValue::find($id);

            if (!$attributeValue) {
                return response()->json([
                    'status' => false,
                    'message' => 'Attribute value not found',
                ], 404);
            }

            $attributeValue->delete();

            return response()->json([
                'status' => true,
                'message' => 'Attribute value deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete attribute value',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
