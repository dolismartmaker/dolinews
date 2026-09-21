<?php

declare(strict_types=1);

namespace Database\Factories\Core\Audit;

use App\Core\Audit\Models\AuditEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEntry>
 */
class AuditEntryFactory extends Factory
{
    /**
     * The model this factory builds.
     *
     * @var class-string<AuditEntry>
     */
    protected $model = AuditEntry::class;

    /**
     * Define the model's default state.
     *
     * Columns mirror the create_core_audit_log_table migration. user_id
     * stays null by default; a test that needs it sets it explicitly.
     * created_at obeys the S12 explicit date-cast contract declared on the
     * model.
     *
     * No @return tag here on purpose: the model declares its properties, so
     * Larastan narrows the parent signature to the model's own keys and any
     * hand-written array<string, mixed> would widen it back.
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'action' => fake()->randomElement([
                'user.login',
                'user.created',
                'token.created',
            ]),
            'subject_type' => fake()->randomElement([
                'App\\Models\\User',
                null,
            ]),
            'subject_id' => fake()->numberBetween(1, 1000),
            'meta' => null,
        ];
    }
}
