<?php

namespace App\Ai\Workflow\Kernel\Contracts;

/**
 * Atomic unit of work — one LLM call, one tool invocation, one
 * deterministic transformation. Distinguished from {@see PipelinePlugin}
 * by having no `steps()` of its own: an agent does its work directly in
 * `execute()`. Cross-agent orchestration only happens in pipelines.
 */
interface AgentPlugin extends Plugin {}
