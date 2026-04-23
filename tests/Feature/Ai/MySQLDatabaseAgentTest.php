<?php

use App\Ai\Agents\AgentType;
use App\Ai\Agents\ChatAgent;
use App\Ai\Agents\MySQLDatabaseAgent;
use App\Ai\Tools\Chat\ManageNotes;
use App\Ai\Tools\Chat\SearchProjectDocuments;
use App\Ai\Tools\MySQLDatabase\AnalyzeSchemaTool;
use App\Ai\Tools\MySQLDatabase\ConnectToDatabaseTool;
use App\Ai\Tools\MySQLDatabase\DescribeTableTool;
use App\Ai\Tools\MySQLDatabase\ExplainQueryTool;
use App\Ai\Tools\MySQLDatabase\ExportQueryCsvTool;
use App\Ai\Tools\MySQLDatabase\FindColumnsTool;
use App\Ai\Tools\MySQLDatabase\ListTablesTool;
use App\Ai\Tools\MySQLDatabase\RunSelectQueryTool;
use App\Ai\Tools\MySQLDatabase\SampleTableTool;
use App\Ai\Tools\MySQLDatabase\ServerInfoTool;
use App\Ai\Tools\MySQLDatabase\SuggestIndexesTool;
use App\Models\Project;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Tool;

/*
 * Guardrails for the read-only MySQL analyst agent. Each attribute
 * encodes a real product constraint we don't want to regress: low
 * temperature for deterministic query writing, a headroom token budget
 * for EXPLAIN dumps, and a dedicated tool tag so the default chat agent
 * never accidentally gets exposed to credential-accepting database
 * tools.
 */

function mysqlAgentAttribute(string $attribute): ReflectionAttribute
{
    $attributes = (new ReflectionClass(MySQLDatabaseAgent::class))->getAttributes($attribute);

    expect($attributes)->toHaveCount(1, $attribute.' must be declared exactly once');

    return $attributes[0];
}

test('MySQLDatabaseAgent runs with a low sampling temperature for deterministic SQL', function () {
    expect(mysqlAgentAttribute(Temperature::class)->getArguments())->toBe([0.1]);
});

test('MySQLDatabaseAgent reserves enough tokens for EXPLAIN plans', function () {
    expect(mysqlAgentAttribute(MaxTokens::class)->getArguments())->toBe([4096]);
});

test('MySQLDatabaseAgent allows enough steps for a multi-table exploration session', function () {
    expect(mysqlAgentAttribute(MaxSteps::class)->getArguments())->toBe([24]);
});

test('MySQLDatabaseAgent enforces a 5 minute timeout', function () {
    expect(mysqlAgentAttribute(Timeout::class)->getArguments())->toBe([300]);
});

test('MySQLDatabaseAgent instructions forbid every write and DDL keyword category', function () {
    $instructions = (string) (new MySQLDatabaseAgent)->instructions();

    expect($instructions)
        ->toContain('ABSOLUTE SAFETY')
        ->toContain('INSERT')
        ->toContain('UPDATE')
        ->toContain('DELETE')
        ->toContain('DROP')
        ->toContain('ALTER')
        ->toContain('TRUNCATE')
        ->toContain('GRANT')
        ->toContain('OUTFILE')
        ->toContain('not executed by the agent');
});

test('MySQLDatabaseAgent instructions name every database tool the agent should use', function () {
    $instructions = (string) (new MySQLDatabaseAgent)->instructions();

    expect($instructions)
        ->toContain('ConnectToDatabaseTool')
        ->toContain('ListTablesTool')
        ->toContain('DescribeTableTool')
        ->toContain('SampleTableTool')
        ->toContain('FindColumnsTool')
        ->toContain('ServerInfoTool')
        ->toContain('RunSelectQueryTool')
        ->toContain('ExplainQueryTool')
        ->toContain('SuggestIndexesTool')
        ->toContain('AnalyzeSchemaTool')
        ->toContain('ExportQueryCsvTool');
});

test('MySQLDatabaseAgent instructions require clarification for ambiguous requests', function () {
    $instructions = (string) (new MySQLDatabaseAgent)->instructions();

    expect($instructions)
        ->toContain('Ambiguity')
        ->toContain('clarifying question');
});

test('MySQLDatabaseAgent instructions document the connection token lifecycle', function () {
    $instructions = (string) (new MySQLDatabaseAgent)->instructions();

    expect($instructions)
        ->toContain('connection_token')
        ->toContain('reconnect');
});

test('MySQLDatabaseAgent instructions warn about MySQL data-shape gotchas', function () {
    $instructions = (string) (new MySQLDatabaseAgent)->instructions();

    expect($instructions)
        ->toContain('row_estimate')
        ->toContain('lower_case_table_names')
        ->toContain('BIGINT UNSIGNED');
});

test('MySQLDatabaseAgent instructions require evidence from schema or data before guessing', function () {
    // Real incident: a `GROUP BY name` query produced one row per
    // project instead of per user because the `projects` table also
    // had a `name` column, and MySQL resolved the bare identifier to
    // the real column instead of the SELECT alias. The current prompt
    // no longer encodes the GROUP BY warning specifically but still
    // forbids guessing schema without evidence — if the model grounds
    // identifier references in Describe/List output it won't hit this
    // class of bug.
    $instructions = (string) (new MySQLDatabaseAgent)->instructions();

    expect($instructions)
        ->toContain('Never guess')
        ->toContain('evidence');
});

test('AgentType registers the MySQL Database agent and maps it to the right class', function () {
    expect(AgentType::MySQLDatabase->value)->toBe('mysql-database');
    expect(AgentType::MySQLDatabase->label())->toBe('MySQL Mode');
    expect(AgentType::MySQLDatabase->agentClass())->toBe(MySQLDatabaseAgent::class);
});

test('AgentType options include the MySQL Database agent for the chat UI selector', function () {
    $keys = array_column(AgentType::options(), 'key');

    expect($keys)->toContain('mysql-database');
});

test('MySQLDatabaseAgent resolves the eleven database tools plus the two core tools', function () {
    // BaseAgent::tools() auto-includes the ai.tools.core tag (ManageNotes,
    // SearchProjectDocuments) so every agent — not just the chat agent
    // — can write notes and search project docs. The MySQL analyst
    // therefore sees its own eleven tools plus those two extras.
    $tools = (new MySQLDatabaseAgent)->tools();

    expect($tools)->toBeArray()->toHaveCount(13);

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(Tool::class);
    }

    expect(array_map('get_class', $tools))->toEqualCanonicalizing([
        ManageNotes::class,
        SearchProjectDocuments::class,
        ConnectToDatabaseTool::class,
        ListTablesTool::class,
        DescribeTableTool::class,
        RunSelectQueryTool::class,
        SampleTableTool::class,
        FindColumnsTool::class,
        ServerInfoTool::class,
        ExplainQueryTool::class,
        SuggestIndexesTool::class,
        AnalyzeSchemaTool::class,
        ExportQueryCsvTool::class,
    ]);
});

test('MySQLDatabaseAgent composes its prompt with the shared context_providers pipeline', function () {
    // The MySQL analyst used to ship with a completely static prompt —
    // per-project schema notes (e.g. "projects is soft-deleted, filter
    // deleted_at IS NULL") registered on a Project had no way to reach
    // the model. Wiring the agent through buildContext() lets
    // ProjectContext surface those notes alongside the baseline
    // instructions without introducing any new prompt machinery.
    $project = Project::factory()->create([
        'system_prompt' => 'When querying the projects table, always filter deleted_at IS NULL.',
    ]);

    $agent = (new MySQLDatabaseAgent)->forProject($project->id);

    expect((string) $agent->instructions())
        ->toContain('senior MySQL data analyst')
        ->toContain('Current Project')
        ->toContain('filter deleted_at IS NULL');
});

test('MySQLDatabaseAgent prompt falls back to the static body when no project is bound', function () {
    $instructions = (string) (new MySQLDatabaseAgent)->instructions();

    expect($instructions)
        ->toContain('senior MySQL data analyst')
        ->not->toContain('Current Project');
});

test('MySQLDatabaseAgent tools are isolated from the default chat agent tool set', function () {
    $chatAgent = new ChatAgent;
    $classes = array_map('get_class', $chatAgent->tools());

    expect($classes)->not->toContain(ConnectToDatabaseTool::class)
        ->and($classes)->not->toContain(RunSelectQueryTool::class);
});
