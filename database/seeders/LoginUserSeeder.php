<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoginUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('login_seed.email');
        if (User::where('email', $email)->exists()) {
            $this->command?->info('Akun login sudah tersedia: '.$email.'. Kata sandi tidak diubah.');

            return;
        }

        $password = config('login_seed.password') ?: Str::password(20);
        User::create(['name' => config('login_seed.name'), 'email' => $email, 'password' => $password, 'crm_role_id' => DB::table('crm_roles')->where('code', 'super_admin')->value('id')]);
        $this->command?->info('Akun login berhasil dibuat.');
        $this->command?->line('Email: '.$email);
        $this->command?->line('Kata sandi: '.$password);
    }
}
