<?php

namespace App\Providers;

use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\Contracts\Plugin;
use App\Ai\Workflow\Kernel\PluginRegistry;
use App\Ai\Workflow\Kernel\Plugins\Agents\ChatStepPlugin;
use App\Ai\Workflow\Kernel\Plugins\Agents\ClassifierStepPlugin;
use App\Ai\Workflow\Kernel\Plugins\Agents\EditorStepPlugin;
use App\Ai\Workflow\Kernel\Plugins\Agents\GeneratorStepPlugin;
use App\Ai\Workflow\Kernel\Plugins\Agents\ResearcherStepPlugin;
use App\Ai\Workflow\Kernel\Plugins\Agents\RewriterStepPlugin;
use App\Ai\Workflow\Kernel\Plugins\Critics\CriticEvaluatorPlugin;
use App\Ai\Workflow\Kernel\Plugins\Pipelines\ChatSpecialistPipelinePlugin;
use App\Ai\Workflow\Kernel\Plugins\Pipelines\ContentPipelinePlugin;
use App\Ai\Workflow\Kernel\Plugins\Pipelines\ResearchPipelinePlugin;
use App\Ai\Workflow\Kernel\Plugins\Pipelines\RouterPipelinePlugin;
use App\Ai\Workflow\Kernel\Plugins\Pipelines\SingleAgentPipelinePlugin;
use App\Ai\Workflow\Kernel\Plugins\Routers\AgentTypeRouter;
use App\Ai\Workflow\Retry\MergeResearchRetryStrategy;
use App\Ai\Workflow\Retry\ReplaceRetryStrategy;
use Illuminate\Support\ServiceProvider;

class KernelServiceProvider extends ServiceProvider
{
    /**
     * Plugin name → class binding. The registry resolves each entry
     * lazily through the container on first use, so plugin
     * dependencies are autowired and shared singletons stay singletons.
     *
     * Adding a new agent / pipeline / router / critic is a one-line
     * change here — no kernel edit, no controller edit.
     *
     * @var array<string, class-string<Plugin>>
     */
    public const PLUGINS = [
        // Agents (atomic step adapters)
        'researcher_step' => ResearcherStepPlugin::class,
        'editor_step' => EditorStepPlugin::class,
        'classifier_step' => ClassifierStepPlugin::class,
        'chat_step' => ChatStepPlugin::class,
        'generator_step' => GeneratorStepPlugin::class,
        'rewriter_step' => RewriterStepPlugin::class,

        // Pipelines
        'single_agent_pipeline' => SingleAgentPipelinePlugin::class,
        'chat_specialist_pipeline' => ChatSpecialistPipelinePlugin::class,
        'content_pipeline' => ContentPipelinePlugin::class,
        'research_pipeline' => ResearchPipelinePlugin::class,
        'router_pipeline' => RouterPipelinePlugin::class,

        // Routers
        'agent_type_router' => AgentTypeRouter::class,

        // Critics
        'default_critic' => CriticEvaluatorPlugin::class,
    ];

    public function register(): void
    {
        $this->app->singleton(PluginRegistry::class);

        $this->app->singleton(AgentKernel::class, function ($app): AgentKernel {
            $registry = $app->make(PluginRegistry::class);

            // The registry needs to be primed before the kernel is used —
            // route() / select() / steps() reach into it on first dispatch.
            foreach (self::PLUGINS as $name => $class) {
                $registry->registerLazy($name, $class);
            }

            return new AgentKernel(
                registry: $registry,
                defaultRetry: $app->make(ReplaceRetryStrategy::class),
                retryStrategies: [
                    // Research keeps its merge semantics — a Critic
                    // rejection triggers a surgical follow-up Researcher
                    // pass + union of findings, never a fresh restart
                    // that would lose the first pass's discoveries.
                    'research_pipeline' => $app->make(MergeResearchRetryStrategy::class),
                ],
            );
        });
    }
}
