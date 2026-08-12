<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Portal;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(private Portal $portal) {}

    public function index(Request $request)
    {
        [$profile, $photoUrl] = $this->profile($request, $this->portal);
        return view('account', [
            'profile'  => $profile,
            'photoUrl' => $photoUrl,
            'account'  => $request->attributes->get('account'),
        ]);
    }

    public function uploadPhoto(Request $request)
    {
        [$profile] = $this->profile($request, $this->portal);

        $file = $request->file('photo');
        if (!$file) {
            // When the POST body exceeds post_max_size, PHP discards $_FILES
            // entirely — so an oversized image looks like "no file". Detect it.
            $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
            $postMax = $this->iniBytes((string) ini_get('post_max_size'));
            if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
                return back()->with('error', 'That image is too large for the server (current limit ' . ini_get('upload_max_filesize') . '). Please use a smaller image, or ask the administrator to raise the upload limit.');
            }
            return back()->with('error', 'Please choose an image to upload.');
        }
        if (!$file->isValid()) {
            // Surfaces "exceeds upload_max_filesize", partial upload, etc.
            return back()->with('error', 'Upload failed: ' . $file->getErrorMessage());
        }
        // Generous ceiling — big phone photos are fine; we downscale below so the
        // stored avatar stays small. Anything larger than the server allows was
        // already rejected above.
        if ($file->getSize() > 10 * 1024 * 1024) {
            return back()->with('error', 'Image must be 10 MB or smaller.');
        }
        $info = @getimagesize($file->getRealPath());
        $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($allowed[$info[2]])) {
            return back()->with('error', 'Only JPG, PNG, or WEBP images are allowed.');
        }

        $enrollmentId = (int) $profile->enrollment_id;
        // Store in the portal's OWN photo folder so the image is served from the
        // portal's domain — reliable on both subfolder and subdomain deploys.
        $dir = $this->portal->studentPhotosPath();
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        if (!is_dir($dir) || !is_writable($dir)) {
            \Log::error('Photo upload: directory not writable', ['dir' => $dir, 'is_dir' => is_dir($dir)]);
            return back()->with('error', 'Photo storage folder is not writable: ' . $dir . ' — please contact the administrator.');
        }
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $old) {
            $p = "$dir/$enrollmentId.$old";
            if (is_file($p)) { @unlink($p); }
        }

        // Downscale + re-encode to a compact JPEG avatar (keeps storage tiny and
        // sidesteps size limits). Falls back to storing the original if GD is
        // unavailable or the resize fails for any reason.
        $target = $enrollmentId . '.jpg';
        if ($this->downscaleToJpeg($file->getRealPath(), $info, $dir . '/' . $target, 512)) {
            \Log::info('Photo upload OK (resized)', ['saved' => $dir . '/' . $target]);
            return back()->with('success', 'Profile photo updated.');
        }

        $target = $enrollmentId . '.' . $allowed[$info[2]];
        try {
            $file->move($dir, $target);
        } catch (\Throwable $e) {
            \Log::error('Photo upload: move failed', ['dir' => $dir, 'error' => $e->getMessage()]);
            return back()->with('error', 'Could not save the photo (' . $e->getMessage() . '). Please try again or contact the administrator.');
        }
        if (!is_file($dir . '/' . $target)) {
            \Log::error('Photo upload: file missing after move', ['expected' => $dir . '/' . $target]);
            return back()->with('error', 'The photo did not save to ' . $dir . '. Please contact the administrator.');
        }

        \Log::info('Photo upload OK', ['saved' => $dir . '/' . $target]);
        return back()->with('success', 'Profile photo updated.');
    }

    /**
     * Resize an image so its longest side is <= $max px and save it as JPEG.
     * Returns false (so the caller can fall back) if GD is missing or it fails.
     */
    private function downscaleToJpeg(string $src, array $info, string $dest, int $max): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }
        try {
            [$w, $h, $type] = $info;
            $img = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
                IMAGETYPE_PNG  => @imagecreatefrompng($src),
                IMAGETYPE_WEBP => @imagecreatefromwebp($src),
                default        => null,
            };
            if (!$img) {
                return false;
            }
            $scale = min(1.0, $max / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            // Flatten any transparency onto white (we output JPEG).
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $ok = imagejpeg($dst, $dest, 85);
            imagedestroy($img);
            imagedestroy($dst);
            return $ok && is_file($dest);
        } catch (\Throwable $e) {
            \Log::error('Photo upload: resize failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Convert a PHP ini shorthand size (e.g. "2M", "8M", "512K") to bytes. */
    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }
}
