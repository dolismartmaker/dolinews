<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApiRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiRequest>
 */
class ApiRequestFactory extends Factory
{
    /**
     * The model this factory builds.
     *
     * @var class-string<ApiRequest>
     */
    protected $model = ApiRequest::class;

    /**
     * Define the model's default state.
     *
     * Columns mirror the create_api_requests_table migration; user_id stays
     * null by default so a row is self-sufficient without pulling in user
     * factories (tests set it explicitly when needed).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'method' => fake()->randomElement(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
            'path' => '/api/v1/'.fake()->slug(),
            'status' => fake()->randomElement([200, 201, 204, 400, 401, 403, 404, 422, 429, 500]),
        ];
    }
}
