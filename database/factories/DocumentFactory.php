<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->word().'.md',
            'disk_path' => 'documents/1/'.fake()->uuid().'.md',
            'mime_type' => 'text/markdown',
            'size' => fake()->numberBetween(100, 50000),
            'status' => DocumentStatus::Ready,
            'chunk_count' => 0,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Pending]);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Processing]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Failed]);
    }
}
