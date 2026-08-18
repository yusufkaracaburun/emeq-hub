<?php

namespace Database\Factories;

use App\Models\AccessRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccessRequest> */
class AccessRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company' => $this->faker->company(),
            'contact_name' => $this->faker->name(),
            'email' => $this->faker->companyEmail(),
            'app_url' => 'https://'.$this->faker->domainName(),
            'providers' => $this->faker->randomElements(['exact', 'mollie', 'snelstart'], 2),
            'message' => $this->faker->optional()->sentence(),
            'status' => 'new',
        ];
    }
}
