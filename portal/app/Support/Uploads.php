<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One file-storage path for the whole Classroom module. Everything lands in the
 * SAME shared uploads root the native app already serves (config portal.uploads_path),
 * under a per-feature subdirectory, with a random stored name (never trust the
 * client filename) and a strict extension/mime whitelist per call.
 */
class Uploads
{
    public const DOC_EXT     = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt'];
    public const IMAGE_EXT   = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    public const ARCHIVE_EXT = ['zip'];
    public const SUBMIT_EXT  = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'jpg', 'jpeg', 'png', 'webp', 'zip'];

    private const IMAGE_TYPE = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];

    public static function base(): string
    {
        return rtrim((string) config('portal.lms_uploads_path'), '/\\');
    }

    public static function url(?string $path = null): ?string
    {
        $root = rtrim((string) config('portal.lms_uploads_url'), '/');
        if ($path === null) {
            return $root;
        }
        return $path === '' ? null : $root . '/' . ltrim($path, '/');
    }

    /**
     * Validate + persist an uploaded file.
     * @return array{file_path:string,file_name:string,file_size:int,mime_type:string}
     * @throws RuntimeException on any validation/IO failure (controllers catch it).
     */
    public static function store(UploadedFile $file, string $subdir, array $allowedExt, int $maxBytes): array
    {
        if (!$file->isValid()) {
            throw new RuntimeException('The file failed to upload. Please try again.');
        }
        if ((int) $file->getSize() > $maxBytes) {
            throw new RuntimeException('File is too large (max ' . (int) round($maxBytes / 1048576) . 'MB).');
        }
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('That file type is not allowed here.');
        }
        // Images: verify real content, not just the extension.
        if (in_array($ext, self::IMAGE_EXT, true)) {
            $info = @getimagesize($file->getRealPath());
            if (!$info || !isset(self::IMAGE_TYPE[$info[2]])) {
                throw new RuntimeException('That image is not a valid JPG, PNG, WEBP, or GIF.');
            }
        }

        $dir = self::base() . '/' . trim($subdir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Upload storage is not writable on the server.');
        }
        // Read size/name/mime BEFORE moving — after move() the temp file is gone
        // and $file->getSize() throws a stat error, which crashed every upload.
        $size = (int) $file->getSize();
        $name = (string) $file->getClientOriginalName();
        $mime = (string) $file->getClientMimeType();

        $stored = Str::random(32) . '.' . $ext;
        $file->move($dir, $stored);

        return [
            'file_path' => trim($subdir, '/') . '/' . $stored,
            'file_name' => $name,
            'file_size' => $size,
            'mime_type' => $mime,
        ];
    }

    /** Delete a stored relative path (best-effort). */
    public static function delete(?string $path): void
    {
        if (!$path) {
            return;
        }
        $full = self::base() . '/' . ltrim($path, '/');
        if (is_file($full)) {
            @unlink($full);
        }
    }

    public static function humanSize(?int $bytes): string
    {
        $bytes = (int) $bytes;
        if ($bytes <= 0) {
            return '';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / (1024 ** $i), $i ? 1 : 0) . ' ' . $units[$i];
    }
}
