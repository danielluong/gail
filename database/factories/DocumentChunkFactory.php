<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    public function definition(): array
    {
        $document = Document::factory();

        return [
            'document_id' => $document,
            'project_id' => fn (array $attrs) => Document::find($attrs['document_id'])->project_id ?? Project::factory(),
            'content' => fake()->paragraph(3),
            'embedding' => null,
            'chunk_index' => 0,
        ];
    }
}
