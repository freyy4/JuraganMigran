<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('security:prune')]
#[Description('Remove expired authentication tokens and security metadata beyond retention')]
class PruneAuthenticationSecurity extends Command
{
    public function handle(): int
    {
        DB::table('auth_challenges')->where('expires_at', '<', now()->subDay())->delete();
        DB::table('auth_reset_tokens')->where('expires_at', '<', now()->subDay())->delete();
        DB::table('auth_devices')->where('expires_at', '<', now())->delete();
        DB::table('auth_factors')->whereNull('confirmed_at')->where('created_at', '<', now()->subDay())->delete();
        $before = now()->subDays(config('security.audit_retention_days'));
        DB::table('auth_security_notifications')->where('created_at', '<', $before)->delete();
        DB::table('auth_session_records')->where('last_active_at', '<', $before)->delete();
        DB::table('audit_logs')->where('created_at', '<', $before)->where(fn ($query) => $query->where('action', 'like', 'auth.%')->orWhere('action', 'like', 'staff.login.%')->orWhere('action', 'like', 'cpmi.login.%')->orWhere('action', 'like', 'portal.login.%'))->delete();
        $this->info('Expired authentication records pruned.');

        return self::SUCCESS;
    }
}
