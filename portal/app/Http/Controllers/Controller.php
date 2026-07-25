<?php

namespace App\Http\Controllers;

use App\Services\Portal;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Load the live profile + photo for the logged-in student, or bail to login.
     * Returns [$profile, $photoUrl, $student(session array)].
     */
    protected function profile(Request $request, Portal $portal): array
    {
        $student = $request->attributes->get('student') ?? $request->session()->get('student');
        $profile = $portal->profile((int) $student['enrollment_id']);
        if (!$profile) {
            $request->session()->forget('student');
            throw new HttpResponseException(
                redirect()->route('login')->with('error', 'Your enrollment record could not be loaded. Please contact the registrar.')
            );
        }
        return [$profile, $portal->photoUrl($profile), $student];
    }
}
