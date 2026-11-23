<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_name' => fake()->unique()->company().' Wedding',
            'bride_name' => fake()->firstNameFemale(),
            'groom_name' => fake()->firstNameMale(),
            'venue_name' => fake()->company().' Hall',
            'event_date' => fake()->dateTimeBetween('+1 week', '+1 year')->format('Y-m-d'),
            'status' => Event::STATUS_ACTIVE,
        ];
    }
}
