<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Portal;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private Portal $portal) {}

    public function index(Request $request)
    {
        [$profile, $photoUrl] = $this->profile($request, $this->portal);

        $asmt     = $this->portal->dashboardAssessment((int) $profile->enrollment_id);
        $balance  = (float) ($asmt->balance ?? 0);
        $paid     = (float) ($asmt->total_paid ?? 0);
        $assessed = (float) ($asmt->net_assessed ?? 0);

        $payStatus = $assessed <= 0 ? 'No Assessment'
            : ($balance <= 0 ? 'Fully Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid'));

        return view('dashboard', [
            'profile'  => $profile,
            'photoUrl' => $photoUrl,
            'assessed' => $assessed,
            'paid'     => $paid,
            'balance'  => $balance,
            'payStatus'   => $payStatus,
            'statusColor' => $this->color($payStatus),
        ]);
    }

    private function color(string $s): string
    {
        return ['Fully Paid' => 'emerald', 'Partially Paid' => 'amber', 'Unpaid' => 'rose', 'No Assessment' => 'slate'][$s] ?? 'slate';
    }
}
