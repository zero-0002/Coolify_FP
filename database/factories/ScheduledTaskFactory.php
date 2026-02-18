<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduledTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'command' => 'echo hello',
            'frequency' => '* * * * *',
            'timeout' => 300,
            'enabled' => true,
            'team_id' => 1,
        ];
    }
}
