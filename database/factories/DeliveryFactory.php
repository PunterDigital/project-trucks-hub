<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    public function definition(): array
    {
        $distance = fake()->randomFloat(1, 50, 1500);
        $hours = $distance / 70;
        $completed = now()->subMinutes(fake()->numberBetween(1, 60 * 24 * 20));

        return [
            'user_id' => User::factory(),
            'client_id' => (string) Str::uuid(),
            'game' => 'ets2',
            'truck_make' => fake()->randomElement(['Scania', 'Volvo', 'DAF', 'MAN', 'Renault']),
            'truck_model' => fake()->randomElement(['S', 'FH', 'XG+', 'TGX', 'T']),
            'source_city' => fake()->randomElement(['Berlin', 'Calais', 'Rotterdam', 'Praha', 'Wien']),
            'source_company' => 'Tradeaux',
            'dest_city' => fake()->randomElement(['Paris', 'Lyon', 'München', 'Brussel', 'Milano']),
            'dest_company' => 'Euroacres',
            'cargo_id' => 'apples',
            'cargo_name' => fake()->randomElement(['Apples', 'Cement', 'Tractors', 'Yoghurt']),
            'cargo_mass_kg' => fake()->randomFloat(1, 5000, 24000),
            'income' => (int) ($distance * fake()->numberBetween(30, 60)),
            'distance_km' => $distance,
            'duration_sec' => (int) ($hours * 3600),
            'avg_speed_kmh' => 70,
            'fuel_used_l' => round($distance * 0.33, 1),
            'damage' => 0.02,
            'started_at' => $completed->subSeconds((int) ($hours * 3600)),
            'completed_at' => $completed,
            'status' => DeliveryStatus::Delivered,
            'source' => 'TELEMETRY',
            'flagged' => false,
            'hidden' => false,
        ];
    }
}
