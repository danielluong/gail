<?php

namespace App\Ai\Workflow\Contracts;

use App\Ai\Workflow\Steps\ResearcherStep;

/**
 * Workflow-level agent. NOT the LLM-level {@see \Laravel\Ai\Contracts\Agent}
 * — that interface exposes prompt()/stream() around a specific model call.
 * This one is the orchestration primitive: an arbitrary unit of work that
 * consumes a context dict and produces an enriched one.
 *
 * Concrete implementations include thin wrappers around LLM agents
 * ({@see ResearcherStep} etc.) and composite
 * {@see Pipeline} classes — both satisfy the same shape, which is why
 * Pipeline extends Agent: a pipeline IS an agent when viewed from the
 * outside.
 *
 * Shared I/O convention (soft-typed, keys optional unless stated):
 *   'query'           string   original user input (required on first call)
 *   'critic_feedback' ?array   attached by the orchestrator on retry
 *   'response'        string   final user-facing text (added by terminal steps)
 *   'research'        array    structured findings (added by ResearcherStep)
 *   'draft'           string   raw generator output (added by GeneratorStep)
 *   'warnings'        list<string>  soft-fail accumulator merged across steps
 */
interface Agent
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(array $input): array;
}
