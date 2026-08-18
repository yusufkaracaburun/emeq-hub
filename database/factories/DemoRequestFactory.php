<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Http\Requests\StoreDemoRequestRequest;
use App\Models\DemoRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DemoRequest> */
class DemoRequestFactory extends Factory
{
    protected $model = DemoRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company' => fake()->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'preferred_slot' => fake()->randomElement(StoreDemoRequestRequest::SLOTS),
            'message' => fake()->optional()->sentence(),
            'privacy_accepted_at' => now(),
            'status' => 'new',
        ];
    }

    public function handled(): static
    {
        return $this->state(fn (): array => ['status' => 'handled']);
    }
}
