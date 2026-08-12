<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Models\Classroom\LessonResource;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class LessonResourceController extends TeacherController
{
    private const DOC_EXT   = ['pdf', 'docx', 'ppt', 'pptx', 'xlsx', 'txt'];
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];
    private const IMAGE_MIME = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    private const MAX_VIDEO_BYTES = 200 * 1024 * 1024; // 200MB
    private const MAX_DOC_BYTES   = 25 * 1024 * 1024;  // 25MB
    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;  // 10MB

    public function store(Request $request, int $lessonId)
    {
        $lesson = $this->ownedLesson($request, $lessonId);

        $type = (string) $request->input('type');
        $request->validate([
            'type'  => ['required', Rule::in(['video_upload', 'video_youtube', 'video_vimeo', 'video_gdrive', 'document', 'image', 'link'])],
            'title' => ['required', 'string', 'max:255'],
        ]);

        $attrs = [
            'lesson_id'  => $lesson->id,
            'type'       => $type,
            'title'      => (string) $request->input('title'),
            'sort_order' => (int) (LessonResource::where('lesson_id', $lesson->id)->max('sort_order') ?? 0) + 1,
            'created_by' => $this->teacher($request)->user_id,
        ];

        try {
            $attrs = match ($type) {
                'video_youtube', 'video_vimeo', 'video_gdrive', 'link' => $attrs + $this->validateUrl($request),
                'video_upload' => $attrs + $this->storeUpload($request, 'file', ['mp4'], self::MAX_VIDEO_BYTES, 'video/'),
                'document'     => $attrs + $this->storeDocument($request),
                'image'        => $attrs + $this->storeImage($request),
                default        => throw new RuntimeException('Unknown resource type.'),
            };
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        LessonResource::create($attrs);

        return back()->with('success', 'Material added.');
    }

    public function destroy(Request $request, int $resourceId)
    {
        $resource = LessonResource::findOrFail($resourceId);
        $lesson = $this->ownedLesson($request, (int) $resource->lesson_id); // authorizes

        if ($resource->file_path) {
            $full = rtrim((string) config('portal.lms_uploads_path'), '/\\') . '/' . $resource->file_path;
            if (is_file($full)) {
                @unlink($full);
            }
        }
        $resource->delete();

        return back()->with('success', 'Material removed.');
    }

    public function reorder(Request $request, int $lessonId)
    {
        $lesson = $this->ownedLesson($request, $lessonId);
        $ids = (array) $request->input('order', []);
        foreach ($ids as $i => $id) {
            LessonResource::where('id', (int) $id)->where('lesson_id', $lesson->id)->update(['sort_order' => $i]);
        }
        return response()->json(['ok' => true]);
    }

    private function validateUrl(Request $request): array
    {
        $url = trim((string) $request->input('url', ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Please enter a valid URL.');
        }
        return ['url' => $url];
    }

    /** Generic whitelist-extension upload (used for video_upload). */
    private function storeUpload(Request $request, string $field, array $allowedExt, int $maxBytes, string $mimePrefix): array
    {
        /** @var UploadedFile|null $file */
        $file = $request->file($field);
        if (!$file || !$file->isValid()) {
            throw new RuntimeException('Please choose a file to upload.');
        }
        if ($file->getSize() > $maxBytes) {
            throw new RuntimeException('File is too large (max ' . round($maxBytes / 1048576) . 'MB).');
        }
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('That file type is not allowed.');
        }
        $mime = (string) $file->getMimeType();
        if ($mimePrefix !== '' && !str_starts_with($mime, $mimePrefix)) {
            throw new RuntimeException('The file content does not match a video file.');
        }

        return $this->persist($file, $ext, $mime);
    }

    private function storeDocument(Request $request): array
    {
        /** @var UploadedFile|null $file */
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            throw new RuntimeException('Please choose a document to upload.');
        }
        if ($file->getSize() > self::MAX_DOC_BYTES) {
            throw new RuntimeException('Document is too large (max 25MB).');
        }
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($ext, self::DOC_EXT, true)) {
            throw new RuntimeException('Allowed document types: PDF, DOCX, PPT, PPTX, XLSX, TXT.');
        }

        return $this->persist($file, $ext, (string) $file->getMimeType());
    }

    private function storeImage(Request $request): array
    {
        /** @var UploadedFile|null $file */
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            throw new RuntimeException('Please choose an image to upload.');
        }
        if ($file->getSize() > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('Image is too large (max 10MB).');
        }
        $info = @getimagesize($file->getRealPath());
        if (!$info || !isset(self::IMAGE_MIME[$info[2]])) {
            throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
        }
        $ext = self::IMAGE_MIME[$info[2]];

        return $this->persist($file, $ext, (string) $file->getMimeType());
    }

    /** Store under the portal's own LMS uploads root (writable + served by the portal). */
    private function persist(UploadedFile $file, string $ext, string $mime): array
    {
        $dir = rtrim((string) config('portal.lms_uploads_path'), '/\\') . '/classroom_lessons';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Upload storage is not writable on the server.');
        }
        // Capture size/name BEFORE the move — afterwards the temp file is gone
        // and getSize() throws a stat error, which broke document/image uploads.
        $size = (int) $file->getSize();
        $name = (string) $file->getClientOriginalName();

        $stored = Str::random(32) . '.' . $ext;
        $file->move($dir, $stored);

        return [
            'file_path' => 'classroom_lessons/' . $stored,
            'file_name' => $name,
            'file_size' => $size,
            'mime_type' => $mime,
        ];
    }
}
