<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterSchoolRequest;
use App\Models\School;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new school (owner signup).
     */
    public function register(RegisterSchoolRequest $request): JsonResponse
    {
        $data = $request->validated();

        $plan = SubscriptionPlan::where('slug', 'starter')->first();

        $school = School::create([
            'subscription_plan_id' => $plan?->id,
            'subscription_tier'    => 'trial',
            'name'                 => $data['school_name'],
            'slug'                 => Str::slug($data['school_name']) . '-' . Str::random(5),
            'email'                => $data['school_email'],
            'phone'                => $data['phone'] ?? null,
            'country'              => $data['country'] ?? null,
            'status'               => 'trial',
            'trial_ends_at'        => now()->addDays(\App\Models\SystemSetting::get('trial_days', 14)),
        ]);

        $owner = User::create([
            'school_id' => $school->id,
            'role'      => 'school_owner',
            'name'      => $data['owner_name'],
            'email'     => $data['owner_email'],
            'password'  => Hash::make($data['password']),
        ]);

        $owner->assignRole('school_owner');

        ActivityLogger::log('school.created', "School '{$school->name}' registered.", [], $school->id, $owner->id);

        $token = $owner->createToken('api')->plainTextToken;

        return response()->json([
            'user'   => $owner->load('school'),
            'school' => $school->load('plan'),
            'token'  => $token,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::with(['school', 'roles'])->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been deactivated.', 'code' => 'ACCOUNT_INACTIVE'], 403);
        }

        if (! $user->isSystemAdmin() && $user->school) {
            if (in_array($user->school->status, ['suspended', 'cancelled'])) {
                return response()->json(['message' => 'School account is inactive.', 'code' => 'SCHOOL_INACTIVE'], 403);
            }
        }

        $user->tokens()->delete();
        $user->update(['last_login_at' => now()]);

        ActivityLogger::log('user.login', "{$user->name} logged in.", [], $user->school_id, $user->id);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Delete ALL tokens for this user (enforces single-session policy).
        // Also works in tests where actingAs() provides no real token object.
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->load(['school.plan', 'roles'])
        );
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'phone'            => 'sometimes|nullable|string|max:30',
            'password'         => 'sometimes|string|min:8|confirmed',
            'current_password' => 'required_with:password|string',
        ]);

        if (isset($data['password'])) {
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
            }
            $data['password'] = Hash::make($data['password']);
        }

        unset($data['current_password']);
        $user->update($data);

        return response()->json($user->fresh());
    }
}
