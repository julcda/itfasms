<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Portal;
use Illuminate\Http\Request;

class GradesController extends Controller
{
    public function __construct(private Portal $portal) {}

    public function index(Request $request)
    {
        [$profile, $photoUrl] = $this->profile($request, $this->portal);
        $sy = $this->portal->activeSy();

        $studentInfoId = $this->portal->studentInfoIdByLrn((string) $profile->lrn, $sy['id']);
        $periods       = $this->portal->releasedPeriods($sy['id']);

        $periodId = (int) $request->query('p', 0);
        if ($periodId > 0 && !collect($periods)->contains(fn ($p) => (int) $p->id === $periodId)) {
            $periodId = 0; // never trust the URL
        }
        if ($periodId === 0 && $periods) {
            $periodId = (int) end($periods)->id; // newest released
        }

        $period = $periodId ? $this->portal->gpGet($periodId) : null;
        $slip   = ($studentInfoId > 0 && $periodId > 0) ? $this->portal->gradeSlip($studentInfoId, $periodId) : null;

        return view('grades', [
            'profile'  => $profile,
            'photoUrl' => $photoUrl,
            'sy'       => $sy,
            'periods'  => $periods,
            'periodId' => $periodId,
            'period'   => $period,
            'slip'     => $slip,
            'portal'   => $this->portal,
        ]);
    }

    public function slip(Request $request)
    {
        [$profile] = $this->profile($request, $this->portal);
        $sy = $this->portal->activeSy();

        $studentInfoId = $this->portal->studentInfoIdByLrn((string) $profile->lrn, $sy['id']);
        $periods       = $this->portal->releasedPeriods($sy['id']);

        $periodId = (int) $request->query('p', 0);
        $ok = collect($periods)->contains(fn ($p) => (int) $p->id === $periodId);
        if (!$ok && $periods) { $periodId = (int) end($periods)->id; $ok = true; }

        $slip = ($ok && $studentInfoId > 0) ? $this->portal->gradeSlip($studentInfoId, $periodId) : null;
        if (!$slip || !$slip['released']) {
            return redirect()->route('grades')->with('error', 'Your grade slip has not been released yet.');
        }

        return view('print.grade_slip', [
            'profile' => $profile,
            'sy'      => $sy,
            'period'  => $this->portal->gpGet($periodId),
            'slip'    => $slip,
            'adviser' => $this->portal->adviserForStudent($studentInfoId, $sy['id']),
            'logo'    => rtrim((string) config('portal.app_base_url'), '/') . '/itfalogo.png',
            'portal'  => $this->portal,
        ]);
    }
}
