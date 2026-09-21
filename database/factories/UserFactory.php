<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The model this factory builds.
     *
     * @var class-string<User>
     */
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'display_name' => null,
            'bio' => null,
            'website' => null,
            'is_moderator' => false,
            'is_super_admin' => false,
            'active' => true,
            'feed_token' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Account with an unverified email (registration flow).
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Member of the moderation team (SPEC 9.1).
     */
    public function moderator(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_moderator' => true,
        ]);
    }

    /**
     * The operator's super admin (SPEC 4.1/5.1).
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_super_admin' => true,
        ]);
    }

    /**
     * Suspended account (moderation act, SPEC 9.3).
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
