<?php

namespace App\Http\Controllers;

use App\Models\DocumentKyc;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DocumentKycController extends Controller
{
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

    private function documentTypes(): array
    {
        return [
            'nid_front',
            'nid_back',
            'trade_license',
            'irc',
        ];
    }

    private function defaultDocumentNames(): array
    {
        return [
            'nid_front' => 'NID Front Side',
            'nid_back' => 'NID Back Side',
            'trade_license' => 'Trade License',
            'irc' => 'Import Registration Certificate - IRC',
        ];
    }

    private function rules(bool $update = false, ?int $ignoreId = null, $userId = null): array
    {
        $required = $update ? 'sometimes' : 'required';

        return [
            'user_id' => [$required, 'nullable', 'integer', 'exists:users,id'],
            'document_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => [
                $required,
                'nullable',
                'string',
                Rule::in($this->documentTypes()),
                Rule::unique('documents_kyc', 'type')
                    ->where(fn ($query) => $query->where('user_id', $userId ?? request('user_id')))
                    ->ignore($ignoreId),
            ],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['pending', 'submitted', 'approved', 'rejected'])],
            'note' => ['sometimes', 'nullable', 'string'],
            'document_file_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'document_file' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    private function storeDocumentFile(Request $request, int $userId): ?string
    {
        if (!$request->hasFile('document_file')) {
            return null;
        }

        return $request->file('document_file')->store("kyc-documents/{$userId}", 'public');
    }

    public function list(Request $request)
    {
        try {
            $query = DocumentKyc::with('user:id,name,email,phone,user_type,role');

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('KYC documents fetched successfully', $query->latest()->get());
            }

            $perPage = (int) $request->get('per_page', 20);

            return $this->success('KYC documents fetched successfully', $query->latest()->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function getByUser($userId)
    {
        try {
            $documents = DocumentKyc::where('user_id', $userId)
                ->orderByRaw("FIELD(type, 'nid_front', 'nid_back', 'trade_license', 'irc')")
                ->get();

            return $this->success('User KYC documents fetched successfully', $documents);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function createDefaultForUser($userId)
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return $this->failed('User not found', null, 404);
            }

            $created = [];

            foreach ($this->defaultDocumentNames() as $type => $name) {
                $created[] = DocumentKyc::firstOrCreate(
                    [
                        'user_id' => $userId,
                        'type' => $type,
                    ],
                    [
                        'document_name' => $name,
                        'status' => 'pending',
                    ]
                );
            }

            return $this->success('Default KYC document rows prepared successfully', $created);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function add(Request $request)
    {
        try {
            $validated = $request->validate($this->rules(false, null, $request->input('user_id')));
            $filePath = !empty($validated['user_id'])
                ? $this->storeDocumentFile($request, (int) $validated['user_id'])
                : null;

            if ($filePath) {
                $validated['document_file_path'] = $filePath;
                $validated['status'] = $validated['status'] ?? 'submitted';
            }

            unset($validated['document_file']);

            if (empty($validated['document_name']) && !empty($validated['type'])) {
                $validated['document_name'] = $this->defaultDocumentNames()[$validated['type']] ?? null;
            }

            $document = DocumentKyc::create($validated);

            return $this->success('KYC document created successfully', $document, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function details($id)
    {
        try {
            $document = DocumentKyc::with('user:id,name,email,phone,user_type,role')->find($id);

            if (!$document) {
                return $this->failed('KYC document not found', null, 404);
            }

            return $this->success('KYC document fetched successfully', $document);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $document = DocumentKyc::find($id);

            if (!$document) {
                return $this->failed('KYC document not found', null, 404);
            }

            $validated = $request->validate($this->rules(true, $document->id, $request->input('user_id', $document->user_id)));
            $userId = (int) ($validated['user_id'] ?? $document->user_id);
            $filePath = $userId ? $this->storeDocumentFile($request, $userId) : null;

            if ($filePath) {
                if ($document->document_file_path && Storage::disk('public')->exists($document->document_file_path)) {
                    Storage::disk('public')->delete($document->document_file_path);
                }

                $validated['document_file_path'] = $filePath;
                $validated['status'] = $validated['status'] ?? 'submitted';
            }

            unset($validated['document_file']);

            if (array_key_exists('type', $validated) && empty($validated['document_name']) && !empty($validated['type'])) {
                $validated['document_name'] = $this->defaultDocumentNames()[$validated['type']] ?? $document->document_name;
            }

            $document->update($validated);

            return $this->success('KYC document updated successfully', $document->fresh('user:id,name,email,phone,user_type,role'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function delete($id)
    {
        try {
            $document = DocumentKyc::find($id);

            if (!$document) {
                return $this->failed('KYC document not found', null, 404);
            }

            if ($document->document_file_path && Storage::disk('public')->exists($document->document_file_path)) {
                Storage::disk('public')->delete($document->document_file_path);
            }

            $document->delete();

            return $this->success('KYC document deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
