<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SubscriptionLimitService;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StudentImportController extends Controller
{
    use ScopedToSchool;

    /**
     * POST /api/v1/school/students/import
     *
     * Accepts a CSV file with columns:
     *   name, email, password, phone, enrollment_number,
     *   date_of_birth, guardian_name, guardian_phone, guardian_email, address
     *
     * Returns:
     *   { created, failed, total, rows: [{ row, status, name, email, errors? }] }
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $school = $this->currentSchool($request);
        $path   = $request->file('file')->getRealPath();
        $rows   = $this->parseCsv($path);

        if (empty($rows)) {
            return response()->json([
                'message' => 'The CSV file is empty or has no data rows.',
            ], 422);
        }

        $results = [];
        $created = 0;
        $failed  = 0;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +2 because row 1 is header, rows are 1-indexed for UX

            // Trim all values
            $row = array_map('trim', $row);

            $name             = $row['name']             ?? '';
            $email            = $row['email']            ?? '';
            $password         = $row['password']         ?? '';
            $phone            = $row['phone']            ?? null;
            $enrollmentNumber = $row['enrollment_number'] ?? null;
            $dateOfBirth      = $row['date_of_birth']    ?? null;
            $guardianName     = $row['guardian_name']    ?? null;
            $guardianPhone    = $row['guardian_phone']   ?? null;
            $guardianEmail    = $row['guardian_email']   ?? null;
            $address          = $row['address']          ?? null;

            // Normalize empty strings to null
            $phone            = $phone            ?: null;
            $enrollmentNumber = $enrollmentNumber ?: null;
            $dateOfBirth      = $dateOfBirth      ?: null;
            $guardianName     = $guardianName     ?: null;
            $guardianPhone    = $guardianPhone    ?: null;
            $guardianEmail    = $guardianEmail    ?: null;
            $address          = $address          ?: null;

            // If password is blank, generate a random one
            $plainPassword = $password ?: Str::random(12);

            // Validate the row
            $validator = Validator::make([
                'name'           => $name,
                'email'          => $email,
                'password'       => $plainPassword,
                'phone'          => $phone,
                'date_of_birth'  => $dateOfBirth,
                'guardian_email' => $guardianEmail,
            ], [
                'name'           => 'required|string|max:255',
                'email'          => 'required|email|unique:users,email',
                'password'       => 'required|string|min:8',
                'phone'          => 'nullable|string|max:30',
                'date_of_birth'  => 'nullable|date',
                'guardian_email' => 'nullable|email',
            ]);

            if ($validator->fails()) {
                $failed++;
                $results[] = [
                    'row'    => $rowNumber,
                    'status' => 'error',
                    'name'   => $name,
                    'email'  => $email,
                    'errors' => collect($validator->errors()->all())->values()->all(),
                ];
                continue;
            }

            try {
                $user = DB::transaction(function () use (
                    $school, $name, $email, $plainPassword, $phone,
                    $enrollmentNumber, $dateOfBirth,
                    $guardianName, $guardianPhone, $guardianEmail, $address
                ) {
                    // Check subscription limit once per successful create
                    SubscriptionLimitService::for($school)->enforce('students');

                    $user = User::create([
                        'school_id' => $school->id,
                        'role'      => 'student',
                        'name'      => $name,
                        'email'     => $email,
                        'phone'     => $phone,
                        'password'  => Hash::make($plainPassword),
                    ]);

                    $user->assignRole('student');

                    StudentProfile::create([
                        'user_id'           => $user->id,
                        'school_id'         => $school->id,
                        'enrollment_number' => $enrollmentNumber ?? $this->generateEnrollmentNumber($school->id),
                        'date_of_birth'     => $dateOfBirth,
                        'guardian_name'     => $guardianName,
                        'guardian_phone'    => $guardianPhone,
                        'guardian_email'    => $guardianEmail,
                        'address'           => $address,
                    ]);

                    return $user;
                });

                $created++;
                $results[] = [
                    'row'    => $rowNumber,
                    'status' => 'created',
                    'name'   => $user->name,
                    'email'  => $user->email,
                ];
            } catch (\Exception $e) {
                $failed++;
                $results[] = [
                    'row'    => $rowNumber,
                    'status' => 'error',
                    'name'   => $name,
                    'email'  => $email,
                    'errors' => [$e->getMessage()],
                ];
            }
        }

        if ($created > 0) {
            ActivityLogger::log(
                'students.imported',
                "Imported {$created} student(s) via CSV" . ($failed > 0 ? " ({$failed} failed)" : '') . '.',
                ['created' => $created, 'failed' => $failed]
            );
        }

        return response()->json([
            'created' => $created,
            'failed'  => $failed,
            'total'   => count($rows),
            'rows'    => $results,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Parse a CSV file into an array of associative arrays keyed by header columns.
     * Skips completely blank rows.
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $headers = null;
        $rows    = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                // Normalize header names: lowercase + trim + strip BOM
                $headers = array_map(fn ($h) => strtolower(trim(ltrim($h, "\xEF\xBB\xBF"))), $line);
                continue;
            }

            // Skip blank rows
            $nonEmpty = array_filter($line, fn ($v) => trim($v) !== '');
            if (empty($nonEmpty)) {
                continue;
            }

            // Map line values to header keys
            $row = [];
            foreach ($headers as $i => $key) {
                $row[$key] = $line[$i] ?? '';
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function generateEnrollmentNumber(int $schoolId): string
    {
        $count = StudentProfile::where('school_id', $schoolId)->count() + 1;
        return strtoupper('S' . $schoolId . str_pad($count, 4, '0', STR_PAD_LEFT));
    }
}
