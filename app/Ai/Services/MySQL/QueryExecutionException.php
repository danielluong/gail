<?php

namespace App\Ai\Services\MySQL;

use RuntimeException;

/**
 * Every failure surface the MySQL AI tools care about — invalid token,
 * unsafe SQL, lint refusal, broken PDO call — funnels through this one
 * exception. The message is guaranteed safe to echo back to the model,
 * so tools can do `return 'Error: '.$e->getMessage()` without any
 * sanitisation or classification of their own.
 */
class QueryExecutionException extends RuntimeException {}
