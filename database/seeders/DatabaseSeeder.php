<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The super admin is the bootstrap operator (SPEC 4.1/5.1): the
     * account exists from the first deployment, its credentials come
     * from the environment so nothing generic ships in the repository.
     */
    public function run(): void
    {
        $email = (string) config('dolinews.super_admin.email', 'admin@dolinews.invalid');
        $name = (string) config('dolinews.super_admin.name', 'Super administrateur DoliNews');

        User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(
                    (string) config('dolinews.super_admin.password', Str::random(32)),
                ),
                'email_verified_at' => now(),
                'is_super_admin' => true,
                'active' => true,
            ],
        );
    }
}
