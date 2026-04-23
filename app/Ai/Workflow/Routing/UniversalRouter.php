<?php

namespace App\Ai\Workflow\Routing;

use App\Actions\Router\RunRouterExample;
use App\Actions\Router\StreamRouterResponse;
use App\Actions\UniversalAssistant\RunUniversalAssistant;
use App\Ai\Workflow\Contracts\Router;
use App\Ai\Workflow\Steps\ClassifierStep;
use App\Enums\InputCategory;
use Illuminate\Support\Facades\Log;

/**
 * Pure-PHP dispatcher for every router-driven flow in the app —
 * {@see RunUniversalAssistant} (JSON),
 * {@see StreamRouterResponse} (SSE),
 * {@see RunRouterExample} (the example JSON endpoint). One place to
 * tune the confidence threshold + fallback policy.
 *
 * Two exposures, same underlying rule:
 *
 * - {@see self::route()} — {@see Router} interface implementation.
 *   Takes the {@see ClassifierStep} output dict and returns the
 *   orchestrator's string path key (`research`, `content`, `chat`).
 *
 * - {@see self::routeCategory()} — low-level enum-to-enum variant.
 *   Callers that need the resolved {@see InputCategory} directly (the
 *   chat-UI router streaming action stashes it on RouterAgent via
 *   fluent setters) use this instead of mapping the path key back.
 *
 * Kept narrow so every routing rule can be exercised by unit tests
 * without mocking anything.
 */
class UniversalRouter implements Router
{
    /**
     * Confidence floor below which the router forces the casual-chat
     * fallback regardless of the classifier's preferred category. The
     * threshold is inclusive at 0.6 — exactly 0.6 keeps the original
     * classification, anything under flips to Chat.
     */
    public const CONFIDENCE_THRESHOLD = 0.6;

    /**
     * @param  array{category?: string, confidence?: float|int, ...}  $classification
     */
    public function route(array $classification): string
    {
        $category = InputCategory::tryFromString((string) ($classification['category'] ?? ''))
            ?? InputCategory::Chat;
        $confidence = (float) ($classification['confidence'] ?? 0.0);

        return match ($this->routeCategory($category, $confidence)) {
            InputCategory::Question => 'research',
            InputCategory::Task => 'content',
            InputCategory::Chat => 'chat',
        };
    }

    /**
     * Enum-to-enum variant. Applies the confidence floor and returns
     * the specialist-category form callers like StreamRouterResponse
     * want (for the RouterAgent fluent setters) without forcing them
     * to map a path key back to a specialist.
     */
    public function routeCategory(InputCategory $category, float $confidence): InputCategory
    {
        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            Log::channel('ai')->info('router.low_confidence_fallback', [
                'original_category' => $category->value,
                'confidence' => $confidence,
                'threshold' => self::CONFIDENCE_THRESHOLD,
            ]);

            return InputCategory::Chat;
        }

        return $category;
    }
}
