<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'name'        => 'sometimes|string|max:255',
            'email'       => "sometimes|email|unique:schools,email,{$school->id}",
            'phone'       => 'sometimes|nullable|string|max:30',
            'address'     => 'sometimes|nullable|string|max:500',
            'city'        => 'sometimes|nullable|string|max:100',
            'country'     => 'sometimes|nullable|string|max:100',
            'timezone'    => 'sometimes|string|timezone',
            'school_type' => 'sometimes|in:regular,course,both',
        ]);

        $school->update($data);

        return response()->json($school->fresh('plan'));
    }
}
