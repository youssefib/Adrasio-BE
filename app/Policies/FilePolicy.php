<?php

namespace App\Policies;

use App\Models\File;
use App\Models\User;

class FilePolicy
{
    public function view(User $user, File $file): bool
    {
        if ($user->role === 'system_admin') return true;
        if ($file->school_id !== $user->school_id) return false;
        if (in_array($user->role, ['school_owner', 'admin'], true)) return true;

        if ($user->role === 'teacher') {
            $classroomIds = $user->taughtClasses()->pluck('id');
            $gradeIds     = $user->taughtClasses()->pluck('grade_id');
            return $file->uploaded_by === $user->id
                || ($file->classroom_id && $classroomIds->contains($file->classroom_id))
                || ($file->grade_id && $gradeIds->contains($file->grade_id))
                || $file->visibility === 'school';
        }

        if ($user->role === 'student') {
            $classroomIds = $user->enrolledClasses()->pluck('classrooms.id');
            $gradeIds     = $user->enrolledClasses()->pluck('grade_id');
            return ($file->classroom_id && $classroomIds->contains($file->classroom_id))
                || ($file->grade_id && $gradeIds->contains($file->grade_id))
                || $file->visibility === 'school';
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->role !== 'student';
    }

    public function update(User $user, File $file): bool
    {
        if (in_array($user->role, ['system_admin', 'school_owner', 'admin'], true)) return true;
        return $file->uploaded_by === $user->id;
    }

    public function delete(User $user, File $file): bool
    {
        return $this->update($user, $file);
    }
}
