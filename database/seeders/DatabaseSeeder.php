<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
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

        $user = User::query()->firstOrNew(['email' => $email]);

        if ($user->exists) {
            return;
        }

        $user->name = $name;
        $user->password = (string) config('dolinews.super_admin.password', Str::random(32));
        $user->email_verified_at = now();

        // Privileges are not mass-assignable on User: they are set here,
        // one by one, where the intent is explicit.
        $user->is_super_admin = true;
        $user->active = true;

        $user->save();
    }
}
