<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * Upload an image/file to `all` folder and create Upload record.
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $mime = $file->getClientMimeType();
        $size = $file->getSize();

        $filename = time() . '_' . Str::random(8) . '.' . $extension;
        $path = 'all/' . $filename;

        Storage::disk('public')->putFileAs('all', $file, $filename);

        $userId = $request->input('user_id');
        $user = $userId ? User::find($userId) : null;

        $upload = Upload::create([
            'file_original_name' => $originalName,
            'file_name' => $path,
            'user_id' => $user ? $user->id : null,
            'file_size' => $size,
            'extension' => $extension,
            'type' => $mime,
            'external_link' => null,
        ]);
        return response()->json(['success' => true, 'data' => $upload], 201);
    }

    /**
     * List uploads (paginated).
     */
    public function listUploads(Request $request)
    {
        $uploads = Upload::orderBy('id', 'desc')->paginate(20);
        return response()->json(['success' => true, 'data' => $uploads]);
    }
    public function listUploadsByUserId($userId)
    {
        $uploads = Upload::where('user_id', $userId)->orderBy('id', 'desc')->paginate(20);
        return response()->json(['success' => true, 'data' => $uploads]);
    }

    /**
     * Get a single upload by id.
     */
    public function getUpload($id)
    {
        $upload = Upload::findOrFail($id);
        return response()->json(['success' => true, 'data' => $upload]);
    }

    /**
     * Delete an upload (soft-delete) and remove stored file if present.
     */
    public function deleteUpload($id)
    {
        $upload = Upload::findOrFail($id);

        if ($upload->file_name && Storage::disk('public')->exists($upload->file_name)) {
            Storage::disk('public')->delete($upload->file_name);
        }

        $upload->delete();

        return response()->json(['success' => true, 'message' => 'Upload deleted']);
    }
}
