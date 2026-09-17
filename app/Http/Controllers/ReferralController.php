<?php

namespace App\Http\Controllers;

use App\Models\Master;
use App\Models\Referral;
use App\Models\ReferralEarning;
use App\Services\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function attach(Request $request, ReferralService $referrals): JsonResponse
    {
        $master = $this->currentMaster($request);

        if (empty($master)) {
            return response()->json(['message' => 'Master not found'], 401);
        }

        $referral = $referrals->registerReferral($master, (string) $request->input('code'));

        if ($referral === null) {
            return response()->json(['message' => 'Invalid referral code'], 422);
        }

        return response()->json($referral);
    }

    public function my(Request $request): JsonResponse
    {
        $master = $this->currentMaster($request);

        if (empty($master)) {
            return response()->json(['message' => 'Master not found'], 401);
        }

        $referrals = Referral::query()
            ->select([
                'masters.name',
                'referrals.created_at as attached_at',
                'referrals.status',
            ])
            ->selectRaw('coalesce(sum(referral_earnings.amount), 0) as earned')
            ->join('masters', 'masters.id', '=', 'referrals.referred_master_id')
            ->leftJoin('referral_earnings', 'referral_earnings.referral_id', '=', 'referrals.id')
            ->where('referrals.referrer_master_id', $master->id)
            ->groupBy('referrals.id', 'masters.name', 'referrals.created_at', 'referrals.status')
            ->orderBy('referrals.created_at')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'attached_at' => $row->attached_at,
                'rewarded' => $row->status === Referral::STATUS_REWARDED,
                'earned' => (int) $row->earned,
            ]);

        return response()->json($referrals);
    }

    public function earnings(Request $request): JsonResponse
    {
        $master = $this->currentMaster($request);

        if (empty($master)) {
            return response()->json(['message' => 'Master not found'], 401);
        }

        $summary = ReferralEarning::query()
            ->where('referrer_master_id', $master->id)
            ->selectRaw('coalesce(sum(amount), 0) as total')
            ->selectRaw('coalesce(sum(case when status = ? then amount else 0 end), 0) as pending', [
                ReferralEarning::STATUS_PENDING,
            ])
            ->selectRaw('coalesce(sum(case when status = ? then amount else 0 end), 0) as paid', [
                ReferralEarning::STATUS_PAID,
            ])
            ->selectRaw('count(*) as rewarded_referrals')
            ->first();

        return response()->json([
            'total' => (int) $summary->total,
            'pending' => (int) $summary->pending,
            'paid' => (int) $summary->paid,
            'rewarded_referrals' => (int) $summary->rewarded_referrals,
        ]);
    }

    private function currentMaster(Request $request): ?Master
    {
        return $request->attributes->get('current_master')
            ?? Master::find($request->header('X-Master-Id'));
    }
}
