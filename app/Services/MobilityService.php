<?php

namespace App\Services;

use App\Models\Application;
use App\Models\MobilityCase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobilityService
{
    public function create(Application $application, array $data, ?int $actor): MobilityCase
    {
        return DB::transaction(function () use ($application, $data, $actor): MobilityCase {
            $case = MobilityCase::create(['code' => 'JM-MOB-'.Str::ulid(), 'application_id' => $application->id, 'leave_request_id' => $data['leave_request_id'] ?? null, 'purpose' => $data['purpose'], 'origin' => $data['origin'], 'destination' => $data['destination'], 'appointment_at' => $data['appointment_at'], 'route_notes' => $data['route_notes'] ?? null, 'contact_name' => $data['contact_name'] ?? null, 'contact_number' => $data['contact_number'] ?? null, 'owner_user_id' => $actor]);
            DB::table('mobility_legs')->insert(['mobility_case_id' => $case->id, 'direction' => 'OUTBOUND', 'transport_mode' => $data['transport_mode'], 'origin' => $case->origin, 'destination' => $case->destination, 'scheduled_at' => $data['scheduled_at'] ?? $case->appointment_at, 'created_at' => now(), 'updated_at' => now()]);

            return $case;
        });
    }

    public function issue(int $legId, Carbon $expires, ?int $actor): object
    {
        return DB::transaction(function () use ($legId, $expires, $actor): object {
            $leg = DB::table('mobility_legs')->where('id', $legId)->lockForUpdate()->first() ?? abort(404);
            if (in_array($leg->status, ['CHECKED_IN', 'CANCELLED'])) {
                throw ValidationException::withMessages(['qr' => 'Perjalanan telah selesai atau dibatalkan.']);
            }
            DB::table('qr_passes')->where('mobility_leg_id', $legId)->whereNull('revoked_at')->whereNull('used_at')->update(['revoked_at' => now()]);
            $token = Str::random(48);
            $id = DB::table('qr_passes')->insertGetId(['reference' => 'JM-QR-'.Str::ulid(), 'mobility_leg_id' => $legId, 'token_hash' => hash('sha256', $token), 'encrypted_token' => Crypt::encryptString($token), 'expires_at' => $expires, 'issued_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('qr_passes')->find($id);
        });
    }

    public function qrStatus(object $pass): string
    {
        return $pass->revoked_at ? 'CANCELLED' : ($pass->used_at ? 'USED' : (Carbon::parse($pass->expires_at)->isPast() ? 'EXPIRED' : 'VALID'));
    }

    public function checkIn(string $token, int $actor): object
    {
        return DB::transaction(function () use ($token, $actor): object {
            $pass = DB::table('qr_passes')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first() ?? abort(404);
            $status = $this->qrStatus($pass);
            DB::table('qr_scan_events')->insert(['qr_pass_id' => $pass->id, 'scanner_id' => $actor, 'action' => 'CHECK_IN', 'result' => $status, 'created_at' => now()]);
            if ($status !== 'VALID') {
                return (object) ['ok' => false, 'status' => $status];
            }
            $leg = DB::table('mobility_legs')->where('id', $pass->mobility_leg_id)->lockForUpdate()->first();
            if (in_array($leg->status, ['CANCELLED', 'CHECKED_IN'])) {
                return (object) ['ok' => false, 'status' => 'CANCELLED'];
            }
            DB::table('qr_passes')->where('id', $pass->id)->update(['used_at' => now(), 'updated_at' => now()]);
            DB::table('mobility_legs')->where('id', $leg->id)->update(['status' => 'CHECKED_IN', 'confirmed_at' => now(), 'updated_at' => now()]);
            $case = MobilityCase::whereKey($leg->mobility_case_id)->lockForUpdate()->firstOrFail();
            if ($case->leave_request_id && $leg->direction === 'OUTBOUND') {
                DB::table('training_leave_requests')->where('id', $case->leave_request_id)->update(['journey_status' => 'AT_HOME', 'updated_at' => now()]);
            }
            if ($case->leave_request_id && $leg->direction === 'INBOUND') {
                DB::table('training_leave_requests')->where('id', $case->leave_request_id)->update(['journey_status' => 'CHECKED_IN', 'return_confirmed_at' => now(), 'updated_at' => now()]);
                DB::table('operations_alerts')->where('dedupe_key', 'like', 'leave-'.$case->leave_request_id.'-%')->update(['resolved_at' => now()]);
            }
            if (! DB::table('mobility_legs')->where('mobility_case_id', $case->id)->whereNotIn('status', ['CHECKED_IN', 'CANCELLED'])->exists()) {
                $case->update(['status' => 'CLOSED']);
            }
            app(AccessPolicy::class)->audit(User::find($actor), 'qr.checked_in', ['pass_id' => $pass->id, 'mobility_case_id' => $case->id]);

            return (object) ['ok' => true, 'status' => 'USED'];
        });
    }

    public function monitor(): int
    {
        $count = 0;
        $leaves = DB::table('training_leave_requests as l')->join('training_enrollments as e', 'e.id', '=', 'l.training_enrollment_id')->join('applications as a', 'a.id', '=', 'e.application_id')->whereNotNull('l.holiday_event_id')->where('l.approval_status', 'approved')->whereNull('l.return_confirmed_at')->whereNotIn('l.journey_status', ['CANCELLED'])->select('l.*', 'a.id as application_id', 'a.candidate_id', 'a.assigned_cs_id')->get();
        foreach ($leaves as $leave) {
            $deadline = Carbon::parse($leave->mandatory_return_at);
            $hours = now()->diffInHours($deadline, false);
            $bucket = $hours < 0 ? 'overdue' : ($hours <= 6 ? '6h' : ($hours <= 24 ? '24h' : null));
            if (! $bucket) {
                continue;
            }
            if ($bucket === 'overdue') {
                DB::table('training_leave_requests')->where('id', $leave->id)->whereNull('return_confirmed_at')->update(['journey_status' => 'OVERDUE_RETURN', 'updated_at' => now()]);
            }
            $text = $bucket === 'overdue' ? 'Batas kembali ke LPK telah terlewati. Hubungi CS untuk koordinasi.' : 'Wajib kembali ke LPK: '.$deadline->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB. Pastikan perjalanan kembali sudah diatur.';
            $count += DB::transaction(function () use ($leave, $bucket, $text): int {
                $current = DB::table('training_leave_requests')->where('id', $leave->id)->lockForUpdate()->first();
                if ($current->return_confirmed_at || $current->approval_status !== 'approved' || $current->journey_status === 'CANCELLED') {
                    return 0;
                }
                $insert = DB::table('operations_alerts')->insertOrIgnore(['dedupe_key' => 'leave-'.$leave->id.'-'.$bucket, 'application_id' => $leave->application_id, 'category' => 'RETURN_REMINDER', 'message' => $text, 'created_at' => now(), 'updated_at' => now()]);
                if ($insert) {
                    if (DB::table('portal_accounts')->where('candidate_id', $leave->candidate_id)->where('status', 'active')->where('in_app_notifications', true)->exists()) {
                        DB::table('portal_notifications')->insert(['candidate_id' => $leave->candidate_id, 'category' => 'TRAVEL_UPDATE', 'title' => 'Pengingat kembali LPK', 'body_safe' => $text, 'deep_link' => '/portal/libur', 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('tasks')->insert(['application_id' => $leave->application_id, 'assigned_to' => $leave->assigned_cs_id, 'task_type' => 'holiday_return', 'title' => 'Koordinasi kembali LPK', 'description' => $text, 'priority' => $bucket === 'overdue' ? 'high' : 'normal', 'due_at' => now(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
                }

                return $insert;
            });
        }

        $legs = DB::table('mobility_legs as l')->join('mobility_cases as m', 'm.id', '=', 'l.mobility_case_id')->join('applications as a', 'a.id', '=', 'm.application_id')->leftJoin('pickup_points as p', 'p.id', '=', 'l.pickup_point_id')->whereIn('l.status', ['PLANNED', 'APPROVED', 'DELAYED'])->whereNotIn('m.status', ['CANCELLED', 'CLOSED'])->whereBetween('l.scheduled_at', [now(), now()->addDay()])->select('l.*', 'm.application_id', 'm.leave_request_id', 'a.candidate_id', 'a.assigned_cs_id', 'p.name as pickup_name')->get();
        foreach ($legs as $leg) {
            if ($leg->leave_request_id && ! DB::table('training_leave_requests')->where('id', $leg->leave_request_id)->where('approval_status', 'approved')->exists()) {
                continue;
            }
            $time = Carbon::parse($leg->scheduled_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB';
            $message = $leg->transport_mode === 'JM_PICKUP_SERVICE' ? 'Penjemputan dijadwalkan '.$time.' di '.($leg->pickup_override ?: $leg->pickup_name ?: $leg->origin).'. Periksa petunjuk perjalanan dan QR di portal.' : 'Perjalanan menuju '.$leg->destination.' dijadwalkan '.$time.'. Periksa panduan rute dan QR di portal.';
            $count += DB::transaction(function () use ($leg, $message): int {
                $current = DB::table('mobility_legs')->where('id', $leg->id)->lockForUpdate()->first();
                if (! in_array($current->status, ['PLANNED', 'APPROVED', 'DELAYED'])) {
                    return 0;
                }
                $insert = DB::table('operations_alerts')->insertOrIgnore(['dedupe_key' => 'pickup-'.$leg->id.'-'.Carbon::parse($leg->scheduled_at)->timestamp, 'application_id' => $leg->application_id, 'category' => 'TRANSPORT_REMINDER', 'message' => $message, 'created_at' => now(), 'updated_at' => now()]);
                if ($insert && DB::table('portal_accounts')->where('candidate_id', $leg->candidate_id)->where('status', 'active')->where('in_app_notifications', true)->exists()) {
                    DB::table('portal_notifications')->insert(['candidate_id' => $leg->candidate_id, 'category' => 'TRAVEL_UPDATE', 'title' => 'Pengingat perjalanan', 'body_safe' => $message, 'deep_link' => '/portal/perjalanan', 'created_at' => now(), 'updated_at' => now()]);
                }

                return $insert;
            });
        }
        foreach (DB::table('tracking_outbox')->where('status', 'DEAD')->get(['id', 'destination_id']) as $outbox) {
            DB::table('operations_alerts')->insertOrIgnore(['dedupe_key' => 'tracking-dead-'.$outbox->id, 'category' => 'TRACKING_DEAD_LETTER', 'message' => 'Pengiriman event #'.$outbox->id.' gagal setelah batas percobaan. Periksa diagnostik pelacakan iklan.', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('operations_alerts')->where('category', 'TRACKING_DEAD_LETTER')->whereNull('resolved_at')->get(['id', 'dedupe_key'])->each(function ($alert): void {
            $outboxId = (int) str_replace('tracking-dead-', '', $alert->dedupe_key);
            if (! DB::table('tracking_outbox')->where('id', $outboxId)->where('status', 'DEAD')->exists()) {
                DB::table('operations_alerts')->where('id', $alert->id)->update(['resolved_at' => now(), 'updated_at' => now()]);
            }
        });

        return $count;
    }
}
