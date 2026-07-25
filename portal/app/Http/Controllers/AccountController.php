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
        if (!$file || !$file->isValid()) {
            return back()->with('error', 'Please choose an image to upload.');
        }
        if ($file->getSize() > 3 * 1024 * 1024) {
            return back()->with('error', 'Image must be 3 MB or smaller.');
        }
        $info = @getimagesize($file->getRealPath());
        $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($allowed[$info[2]])) {
            return back()->with('error', 'Only JPG, PNG, or WEBP images are allowed.');
        }

        $enrollmentId = (int) $profile->enrollment_id;
        $dir = $this->portal->sharedUploadsPath() . '/student_photos';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        if (!is_dir($dir) || !is_writable($dir)) {
            return back()->with('error', 'Photo storage is not writable on the server. Please contact the registrar.');
        }
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $old) {
            $p = "$dir/$enrollmentId.$old";
            if (is_file($p)) { @unlink($p); }
        }
        $file->move($dir, $enrollmentId . '.' . $allowed[$info[2]]);

        return back()->with('success', 'Profile photo updated.');
    }
}
