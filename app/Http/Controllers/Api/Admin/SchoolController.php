<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * School-owner & admin: manage their own school profile.
 * (Full school CRUD for system admin is in SystemAdminController.)
 */
class SchoolController extends Controller
{
    use ScopedToSchool;

    public function show(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        return response()->json($school->load('plan'));
    }

    public function update(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'name'                    => 'sometimes|string|max:255',
            'email'                   => "sometimes|email|unique:schools,email,{$school->id}",
            'phone'                   => 'sometimes|nullable|string|max:30',
            'address'                 => 'sometimes|nullable|string|max:500',
            'city'                    => 'sometimes|nullable|string|max:100',
            'country'                 => 'sometimes|nullable|string|max:100',
            'timezone'                => 'sometimes|string|timezone',
            'school_type'             => 'sometimes|in:regular,course,both',
            'students_access_enabled' => 'sometimes|boolean',
            'teachers_access_enabled' => 'sometimes|boolean',
        ]);

        $school->update($data);

        return response()->json($school->fresh('plan'));
    }

    /**
     * Upload / replace the school logo. Stored on the public disk so it can be
     * displayed directly (login screen, sidebar) via a plain URL.
     *
     * SVG is intentionally excluded (XSS risk when served from a public URL).
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $request->validate([
            'logo' => 'required|file|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // Remove the previous logo so old files don't pile up.
        if ($school->logo) {
            Storage::disk('public')->delete($school->logo);
        }

        $ext  = $request->file('logo')->getClientOriginalExtension();
        $path = $request->file('logo')->storeAs(
            "schools/{$school->id}",
            'logo-' . now()->timestamp . '.' . $ext,
            'public',
        );

        $school->update(['logo' => $path]);

        return response()->json($school->fresh('plan'));
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        if ($school->logo) {
            Storage::disk('public')->delete($school->logo);
            $school->update(['logo' => null]);
        }

        return response()->json($school->fresh('plan'));
    }
}
