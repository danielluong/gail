<?php

namespace App\Providers;

use App\Ai\Context\GlobalNotesContext;
use App\Ai\Context\ProjectContext;
use App\Ai\Context\ProjectScope;
use App\Ai\Support\Limerick\Pronunciation;
use App\Ai\Tools\Chat\Calculator;
use App\Ai\Tools\Chat\CurrentDateTime;
use App\Ai\Tools\Chat\CurrentLocation;
use App\Ai\Tools\Chat\GenerateImage;
use App\Ai\Tools\Chat\ManageNotes;
use App\Ai\Tools\Chat\SearchProjectDocuments;
use App\Ai\Tools\Chat\Weather;
use App\Ai\Tools\Chat\WebFetch;
use App\Ai\Tools\Chat\WebSearch;
use App\Ai\Tools\Chat\Wikipedia;
use App\Ai\Tools\Limerick\FindRhymesTool;
use App\Ai\Tools\Limerick\PronounceWordTool;
use App\Ai\Tools\Limerick\ValidateLimerickTool;
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
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProjectScope::class);
        $this->app->scoped(Pronunciation::class);

        $this->app->tag([
            GlobalNotesContext::class,
            ProjectContext::class,
        ], 'ai.context_providers');

        /*
         * Tools every BaseAgent gets for free. The memory read-side
         * (GlobalNotesContext, ProjectContext) already flows into every
         * agent via the context pipeline; binding ManageNotes and
         * SearchProjectDocuments here closes the loop so any agent can
         * also *write* notes and *search* project documents, not just
         * the chat agent. BaseAgent::tools() auto-merges this tag.
         */
        $this->app->tag([
            ManageNotes::class,
            SearchProjectDocuments::class,
        ], 'ai.tools.core');

        $this->app->tag([
            Calculator::class,
            CurrentDateTime::class,
            CurrentLocation::class,
            ...(config('ai.default_for_images') !== null ? [GenerateImage::class] : []),
            Weather::class,
            WebFetch::class,
            WebSearch::class,
            Wikipedia::class,
        ], 'ai.tools.chat');

        $this->app->tag([
            FindRhymesTool::class,
            PronounceWordTool::class,
            ValidateLimerickTool::class,
        ], 'ai.tools.limerick');

        $this->app->tag([
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
        ], 'ai.tools.mysql_database');
    }
}
