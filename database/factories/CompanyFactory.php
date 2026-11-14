<?php

namespace Database\Factories;

use App\Enums\Recruitment;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company().' Logistics';

        return [
            'name' => Str::limit($name, 60, ''),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'tag' => Str::upper(Str::random(4)),
            'description' => fake()->sentence(),
            'recruitment' => Recruitment::Apply,
        ];
    }

    public function open(): static
    {
        return $this->state(['recruitment' => Recruitment::Open]);
    }
}
