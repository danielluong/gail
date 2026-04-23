<?php

namespace App\Ai\Workflow\Contracts;

/**
 * A sequence of {@see Agent}s that runs them in order, threading the
 * enriched context from one into the next. Deliberately extends Agent
 * with no extra methods — a pipeline IS an agent from the outside
 * (composite pattern), so the orchestrator's `string => Agent` path map
 * can hold pipelines and bare agents uniformly.
 */
interface Pipeline extends Agent {}
