<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\School\StoreUserRequest;
use App\Http\Requests\School\UpdateUserRequest;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SubscriptionLimitService;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    use ScopedToSchool;

    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $perPage = min((int) ($request->per_page ?? 25), 9999);

        $users = $school->users()
            ->with('roles')
            ->when($request->role, fn ($q) => $q->role($request->role))
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('email', 'like', "%{$request->search}%"))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $school = $this->currentSchool($request);
        $data   = $request->validated();

        // Enforce subscription limits
        SubscriptionLimitService::for($school)->enforce("{$data['role']}s");

        $user = DB::transaction(function () use ($school, $data) {
            $user = User::create([
                'school_id' => $school->id,
                'role'      => $data['role'],
                'name'      => $data['name'],
                'email'     => $data['email'],
                'phone'     => $data['phone'] ?? null,
                'password'  => Hash::make($data['password']),
            ]);

            $user->assignRole($data['role']);

            // Auto-create StudentProfile when role is student
            if ($data['role'] === 'student') {
                StudentProfile::create([
                    'user_id'           => $user->id,
                    'school_id'         => $school->id,
                    'enrollment_number' => $data['enrollment_number'] ?? $this->generateEnrollmentNumber($school->id),
                    'date_of_birth'     => $data['date_of_birth'] ?? null,
                    'guardian_name'     => $data['guardian_name'] ?? null,
                    'guardian_phone'    => $data['guardian_phone'] ?? null,
                    'guardian_email'    => $data['guardian_email'] ?? null,
                    'address'           => $data['address'] ?? null,
                ]);
            }

            return $user;
        });

        ActivityLogger::log('user.created', "User '{$user->name}' ({$data['role']}) created.", [
            'user_id' => $user->id,
        ]);

        return response()->json($user->load(['roles', 'studentProfile']), 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeSchoolAccess($request, $user);

        return response()->json(
            $user->load(['roles', 'enrolledClasses.grade', 'taughtClasses.grade', 'studentProfile'])
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorizeSchoolAccess($request, $user);

        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
            $user->role = $data['role'];
            unset($data['role']);
        }

        $user->update($data);

        ActivityLogger::log('user.updated', "User '{$user->name}' updated.");

        return response()->json($user->fresh(['roles', 'studentProfile']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeSchoolAccess($request, $user);
        abort_if($user->isSchoolOwner(), 403, 'Cannot delete the school owner.');

        ActivityLogger::log('user.deleted', "User '{$user->name}' deleted.");

        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    private function authorizeSchoolAccess(Request $request, User $user): void
    {
        $school = $this->currentSchool($request);
        abort_if($user->school_id !== $school->id, 403, 'User does not belong to this school.');
    }

    private function generateEnrollmentNumber(int $schoolId): string
    {
        $count = StudentProfile::where('school_id', $schoolId)->count() + 1;
        return strtoupper('S' . $schoolId . str_pad($count, 4, '0', STR_PAD_LEFT));
    }
}
