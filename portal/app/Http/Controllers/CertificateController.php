<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Portal;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function __construct(private Portal $portal) {}

    public function index(Request $request)
    {
        [$profile, $photoUrl] = $this->profile($request, $this->portal);
        $sy = $this->portal->activeSy();
        $studentInfoId = $this->portal->studentInfoIdByLrn((string) $profile->lrn, $sy['id']);

        return view('certificates', [
            'profile'  => $profile,
            'photoUrl' => $photoUrl,
            'certs'    => $this->portal->certificatesForStudent($studentInfoId),
        ]);
    }

    public function print(Request $request, int $id)
    {
        [$profile] = $this->profile($request, $this->portal);
        $sy = $this->portal->activeSy();
        $studentInfoId = $this->portal->studentInfoIdByLrn((string) $profile->lrn, $sy['id']);

        $cert = $this->portal->certificate($id, $studentInfoId);
        if (!$cert) {
            return redirect()->route('certificates')->with('error', 'That certificate is not available.');
        }

        return view('print.certificate', [
            'cert'      => $cert,
            'principal' => $this->portal->setting('PRINCIPAL_NAME', ''),
            'logo'      => rtrim((string) config('portal.app_base_url'), '/') . '/itfalogo.png',
            'verifyUrl' => rtrim((string) config('portal.app_base_url'), '/') . '/verify.php',
        ]);
    }
}
