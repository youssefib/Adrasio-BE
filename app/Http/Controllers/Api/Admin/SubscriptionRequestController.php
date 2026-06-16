<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SubscriptionRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SubscriptionRequestController extends Controller
{
    public function index(Request $r)
    {
        $query = SubscriptionRequest::with(['school', 'plan', 'reviewer'])
            ->orderByDesc('created_at');

        if ($r->filled('status')) {
            $query->where('status', $r->status);
        }

        return response()->json($query->paginate(20));
    }

    public function approve(Request $r, SubscriptionRequest $subscriptionRequest)
    {
        $data = $r->validate([
            'starts_at'   => 'required|date',
            'admin_notes' => 'nullable|string',
        ]);

        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt   = $startsAt->copy()->addMonths($subscriptionRequest->duration_months);

        $subscriptionRequest->update([
            'status'      => 'approved',
            'reviewed_by' => $r->user()->id,
            'starts_at'   => $startsAt->toDateString(),
            'ends_at'     => $endsAt->toDateString(),
            'admin_notes' => $data['admin_notes'] ?? null,
        ]);

        // Map plan slug to subscription_tier
        $tierMap = [
            'starter' => 'tier1',
            'pro'     => 'tier2',
        ];
        $plan  = $subscriptionRequest->plan;
        $tier  = $tierMap[$plan->slug] ?? 'tier1';

        $subscriptionRequest->school->update([
            'subscription_tier'        => $tier,
            'subscription_ends_at'     => $endsAt->toDateString(),
            'subscription_plan_id'     => $plan->id,
        ]);

        return response()->json($subscriptionRequest->fresh(['school', 'plan', 'reviewer']));
    }

    public function reject(Request $r, SubscriptionRequest $subscriptionRequest)
    {
        $data = $r->validate([
            'admin_notes' => 'required|string',
        ]);

        $subscriptionRequest->update([
            'status'      => 'rejected',
            'reviewed_by' => $r->user()->id,
            'admin_notes' => $data['admin_notes'],
        ]);

        return response()->json($subscriptionRequest->fresh(['school', 'plan', 'reviewer']));
    }

    public function pendingCount()
    {
        $pendingRequests = SubscriptionRequest::where('status', 'pending')->count();
        $expiringSchools = School::whereNotNull('subscription_ends_at')
            ->whereDate('subscription_ends_at', '>=', now()->toDateString())
            ->whereDate('subscription_ends_at', '<=', now()->addDays(30)->toDateString())
            ->count();

        return response()->json([
            'count'           => $pendingRequests + $expiringSchools,
            'pending_requests' => $pendingRequests,
            'expiring_schools' => $expiringSchools,
        ]);
    }
}
