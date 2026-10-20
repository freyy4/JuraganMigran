<?php

namespace App\Services;

use App\Models\Application;
use App\Models\HolidayEvent;
use App\Models\HolidayPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HolidayEligibility
{
    public function evaluate(Application $application, HolidayEvent $holiday, ?Carbon $returnAt = null): array
    {
        $returnAt ??= $holiday->return_deadline;
        $flight = DB::table('flight_details')->where('application_id', $application->id)->whereNotNull('departure_at')->orderBy('departure_at')->first();
        $departure = $flight ? Carbon::parse($flight->departure_at) : null;
        $enrollment = DB::table('training_enrollments')->join('training_batches', 'training_batches.id', '=', 'training_enrollments.batch_id')->where('application_id', $application->id)->whereIn('training_enrollments.status', ['ongoing', 'on_hold'])->latest('training_enrollments.id')->first(['training_batches.company_id']);
        $order = $application->vacancy?->jobOrder;
        $context = ['country_id' => $order?->country_id, 'sponsor_id' => $order?->company_id, 'job_order_id' => $order?->id, 'vacancy_id' => $application->vacancy_id, 'lpk_id' => $enrollment?->company_id];
        if (! $enrollment || ! in_array((int) $enrollment->company_id, array_map('intval', $holiday->lpk_ids), true) || ($holiday->country_ids && ! in_array((int) $order?->country_id, array_map('intval', $holiday->country_ids), true))) {
            return ['result' => 'NOT_ALLOWED', 'reason' => 'Libur ini tidak berlaku untuk LPK atau program Anda.', 'policy_id' => $holiday->policy_id, 'departure_at' => $departure?->toISOString(), 'return_at' => $returnAt->toISOString()];
        }
        $policy = $holiday->policy;
        foreach (HolidayPolicy::where('active', true)->get()->sort(fn ($a, $b) => count($b->scope) <=> count($a->scope) ?: $b->id <=> $a->id) as $candidate) {
            $scope = $candidate->scope;
            if (! $scope) {
                continue;
            }
            $match = true;
            foreach ($scope as $key => $value) {
                if (($context[$key] ?? null) != (int) $value) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $policy = $candidate;
                break;
            }
        }
        if (! $policy?->active) {
            return ['result' => 'REQUIRES_REVIEW', 'reason' => 'Kebijakan libur perlu dikonfirmasi oleh tim.', 'policy_id' => $policy?->id];
        }
        $result = 'ALLOWED';
        $reason = 'Jadwal kembali sesuai kebijakan libur. Pengajuan tetap perlu disetujui.';
        $rank = ['ALLOWED' => 0, 'REQUIRES_REVIEW' => 1, 'RESTRICTED' => 2, 'NOT_ALLOWED' => 3];
        foreach ($policy->rules as $rule) {
            $matches = match ($rule['condition']) {
                'departure_within_days' => $departure && $returnAt->diffInDays($departure, false) <= (int) $rule['days'],'departure_before_return' => $departure && $departure->lte($returnAt),'readiness_incomplete' => ! $application->isReadyForDeparture(),'departure_unknown' => ! $departure,default => false
            };
            if ($matches && $rank[$rule['result']] > $rank[$result]) {
                $result = $rule['result'];
                $reason = $rule['reason'];
            }
        }

        return ['result' => $result, 'reason' => $reason, 'policy_id' => $policy->id, 'policy_version' => $policy->version, 'departure_at' => $departure?->toISOString(), 'return_at' => $returnAt->toISOString(), 'evaluated_at' => now()->toISOString()];
    }
}
