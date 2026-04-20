<?php

namespace App\Ai\Tools\Chat;

use App\Ai\Contracts\DisplayableTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Image;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class GenerateImage implements DisplayableTool, Tool
{
    protected const DISK = 'public';

    protected const DIRECTORY = 'ai-images';

    /** @var list<string> */
    protected const ALLOWED_SIZES = ['1:1', '3:2', '2:3'];

    public function label(): string
    {
        return 'Generated an image';
    }

    public function description(): Stringable|string
    {
        return 'Generate an image from a text prompt using the configured image provider and embed it inline as markdown. Use this whenever the user asks you to create, draw, illustrate, visualize, or imagine an image. Write a vivid, descriptive prompt that specifies subject, style, mood, and composition.';
    }

    public function handle(Request $request): Stringable|string
    {
        $prompt = trim((string) ($request['prompt'] ?? ''));

        if ($prompt === '') {
            return 'Error: A prompt is required to generate an image.';
        }

        $size = $this->normalizeSize($request['size'] ?? null);

        try {
            $pending = Image::of($prompt);

            if ($size !== null) {
                $pending = $pending->size($size);
            }

            $response = $pending->generate();
        } catch (Throwable $e) {
            return "Error: Image generation failed — {$e->getMessage()}";
        }

        $path = $response->storePublicly(self::DIRECTORY, self::DISK);

        if (! is_string($path) || $path === '') {
            return 'Error: Image was generated but could not be stored.';
        }

        $url = Storage::disk(self::DISK)->url($path);
        $alt = strtr($prompt, ['[' => '', ']' => '']);

        return "![{$alt}]({$url})";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('A vivid, descriptive prompt for the image. Specify subject, style, mood, and composition.')
                ->required(),
            'size' => $schema->string()
                ->description("Aspect ratio for the generated image: '1:1' (square), '3:2' (landscape), or '2:3' (portrait). Defaults to '1:1' when omitted.")
                ->enum(self::ALLOWED_SIZES)
                ->required()
                ->nullable(),
        ];
    }

    private function normalizeSize(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return in_array($value, self::ALLOWED_SIZES, true) ? $value : null;
    }
}
