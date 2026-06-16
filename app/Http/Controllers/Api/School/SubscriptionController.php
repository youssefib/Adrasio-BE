<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\BankingInfo;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRequest;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function plans()
    {
        return response()->json(SubscriptionPlan::where('is_active', true)->get());
    }

    public function bankingInfo()
    {
        return response()->json(BankingInfo::where('is_active', true)->orderBy('order')->get());
    }

    public function current(Request $r)
    {
        $school = $r->user()->school->load('plan');
        $latestRequest = SubscriptionRequest::where('school_id', $school->id)
            ->with('plan')
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'school'          => $school,
            'latest_request'  => $latestRequest,
        ]);
    }

    public function createRequest(Request $r)
    {
        $data = $r->validate([
            'plan_id'         => 'required|exists:subscription_plans,id',
            'duration_months' => 'required|in:3,6,12',
        ]);

        $plan = SubscriptionPlan::findOrFail($data['plan_id']);

        // Compute amount based on duration
        $amount = match ((int) $data['duration_months']) {
            3  => $plan->price_3months,
            6  => $plan->price_6months,
            12 => $plan->price_yearly,
        };

        $schoolId = $r->user()->school_id;

        // Cancel any existing pending requests
        SubscriptionRequest::where('school_id', $schoolId)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        $request = SubscriptionRequest::create([
            'school_id'       => $schoolId,
            'plan_id'         => $plan->id,
            'duration_months' => (int) $data['duration_months'],
            'amount'          => $amount,
            'status'          => 'pending',
        ]);

        return response()->json($request->load('plan'), 201);
    }

    public function uploadProof(Request $r, SubscriptionRequest $subscriptionRequest)
    {
        // Ensure the request belongs to this school
        abort_if($subscriptionRequest->school_id !== $r->user()->school_id, 403);

        $r->validate([
            'proof' => 'required|file|max:5120|mimes:jpg,jpeg,png,pdf',
        ]);

        $path = $r->file('proof')->store(
            'subscription-proofs/' . $r->user()->school_id,
            'local'
        );

        $subscriptionRequest->update(['proof_path' => $path]);

        return response()->json($subscriptionRequest->fresh('plan'));
    }
}
