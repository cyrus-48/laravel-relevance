<?php

declare(strict_types=1);

namespace Relevance;

use Illuminate\Support\ServiceProvider;
use Relevance\Analytics\SearchLogger;
use Relevance\Console\RelevanceDoctorCommand;
use Relevance\Console\RelevanceExplainCommand;
use Relevance\Console\RelevanceIndexRebuildCommand;
use Relevance\Console\RelevanceIndexSwapCommand;
use Relevance\Console\RelevanceSyncCommand;
use Relevance\Drivers\DriverManager;
use Relevance\Embeddings\EmbeddingCache;
use Relevance\Embeddings\EmbeddingInputBuilder;
use Relevance\Embeddings\EmbeddingManager;
use Relevance\Embeddings\TokenBudgetGuard;
use Relevance\Indexing\GenerationManager;
use Relevance\Indexing\OutboxDispatcher;
use Relevance\Schema\IndexRegistry;
use Relevance\Search\Boosts\BoostEngine;
use Relevance\Search\Profiles\ProfileRepository;
use Relevance\Search\Rules\RuleEngine;
use Relevance\Search\Rules\RuleRepository;
use Relevance\Search\Understanding\QueryNormalizer;
use Relevance\Search\Understanding\SynonymExpander;
use Relevance\Recommendations\PlacementRegistry;
use Relevance\Search\Understanding\SynonymRepository;
use Relevance\Tracking\Tracker;

final class RelevanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/relevance.php', 'relevance');

        $this->app->singleton(DriverManager::class, static function ($app) {
            return new DriverManager($app['config']->get('relevance.driver', 'memory'));
        });

        $this->app->singleton(IndexRegistry::class, static function ($app) {
            return new IndexRegistry($app['config']->get('relevance.indexes', []));
        });

        $this->app->singleton(GenerationManager::class, static function ($app) {
            return new GenerationManager($app->make(DriverManager::class));
        });

        $this->app->singleton(EmbeddingCache::class, static function () {
            return new EmbeddingCache();
        });

        $this->app->singleton(TokenBudgetGuard::class, static function ($app) {
            $budget = (int) $app['config']->get('relevance.embeddings.budget.daily_tokens', 20_000_000);
            $action = (string) $app['config']->get('relevance.embeddings.budget.on_exhausted', 'defer');
            return new TokenBudgetGuard($budget, $action);
        });

        $this->app->singleton(EmbeddingInputBuilder::class, static function () {
            return new EmbeddingInputBuilder();
        });

        $this->app->singleton(EmbeddingManager::class, static function ($app) {
            return new EmbeddingManager(
                $app->make(EmbeddingCache::class),
                $app->make(TokenBudgetGuard::class),
                (string) $app['config']->get('relevance.embeddings.default', 'fake'),
            );
        });

        $this->app->singleton(OutboxDispatcher::class, static function ($app) {
            return new OutboxDispatcher(
                $app->make(DriverManager::class),
                $app->make(GenerationManager::class),
                $app->make(IndexRegistry::class),
                $app->make(EmbeddingManager::class),
                $app->make(EmbeddingInputBuilder::class),
            );
        });

        $this->app->singleton(ProfileRepository::class, static function ($app) {
            return new ProfileRepository((array) $app['config']->get('relevance.profiles', []));
        });

        $this->app->singleton(RuleRepository::class, static function () {
            return new RuleRepository();
        });

        $this->app->singleton(RuleEngine::class, static function ($app) {
            return new RuleEngine(
                $app->make(RuleRepository::class),
                $app->make(ProfileRepository::class),
            );
        });

        $this->app->singleton(SynonymRepository::class, static function () {
            return new SynonymRepository();
        });

        $this->app->singleton(SynonymExpander::class, static function ($app) {
            return new SynonymExpander($app->make(SynonymRepository::class));
        });

        $this->app->singleton(QueryNormalizer::class, static function () {
            return new QueryNormalizer();
        });

        $this->app->singleton(BoostEngine::class, static function () {
            return new BoostEngine();
        });

        $this->app->singleton(SearchLogger::class, static function () {
            return new SearchLogger();
        });

        $this->app->singleton(Tracker::class, static function () {
            return new Tracker();
        });

        $this->app->singleton(PlacementRegistry::class, static function ($app) {
            return new \Relevance\Recommendations\PlacementRegistry(
                (array) $app['config']->get('relevance.recommendations.placements', [])
            );
        });

        $this->app->singleton(\Relevance\Recommendations\Generators\GeneratorRegistry::class, static function () {
            return new \Relevance\Recommendations\Generators\GeneratorRegistry();
        });

        $this->app->singleton(\Relevance\Recommendations\Filters\FilterRegistry::class, static function () {
            return new \Relevance\Recommendations\Filters\FilterRegistry();
        });

        $this->app->singleton(\Relevance\Recommendations\PlacementRunner::class, static function ($app) {
            return new \Relevance\Recommendations\PlacementRunner(
                $app->make(PlacementRegistry::class),
                $app->make(\Relevance\Recommendations\Generators\GeneratorRegistry::class),
                $app->make(\Relevance\Recommendations\Filters\FilterRegistry::class),
            );
        });

        $this->app->singleton(\Relevance\Recommendations\Training\ItemKnnTrainer::class, static function () {
            return new \Relevance\Recommendations\Training\ItemKnnTrainer();
        });

        $this->app->singleton(\Relevance\Experiments\ExperimentManager::class, static function () {
            return new \Relevance\Experiments\ExperimentManager();
        });

        $this->app->singleton(\Relevance\Evaluation\EvaluationRunner::class, static function () {
            return new \Relevance\Evaluation\EvaluationRunner();
        });

        $this->app->singleton(\Relevance\Search\Reranking\RerankManager::class, static function ($app) {
            return new \Relevance\Search\Reranking\RerankManager(
                (array) $app['config']->get('relevance.search.rerankers', [])
            );
        });

        $this->app->singleton(\Relevance\Recommendations\Artifacts\ArtifactManager::class, static function () {
            return new \Relevance\Recommendations\Artifacts\ArtifactManager();
        });

        $this->app->singleton(\Relevance\Support\Privacy\PrivacyManager::class, static function () {
            return new \Relevance\Support\Privacy\PrivacyManager();
        });

        $this->app->singleton(RelevanceManager::class, static function ($app) {
            return new RelevanceManager(
                $app,
                $app->make(DriverManager::class),
                $app->make(EmbeddingManager::class),
                $app->make(ProfileRepository::class),
                $app->make(RuleEngine::class),
                $app->make(RuleRepository::class),
                $app->make(SynonymRepository::class),
                $app->make(SynonymExpander::class),
                $app->make(QueryNormalizer::class),
                $app->make(BoostEngine::class),
                $app->make(SearchLogger::class),
                $app->make(Tracker::class),
                $app->make(\Relevance\Recommendations\PlacementRunner::class),
                $app->make(PlacementRegistry::class),
                $app->make(\Relevance\Experiments\ExperimentManager::class),
                $app->make(\Relevance\Evaluation\EvaluationRunner::class),
                $app->make(\Relevance\Search\Reranking\RerankManager::class),
            );
        });

        $this->app->alias(RelevanceManager::class, 'relevance');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/relevance.php' => config_path('relevance.php'),
        ], 'relevance-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'relevance-migrations');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RelevanceDoctorCommand::class,
                RelevanceSyncCommand::class,
                RelevanceIndexRebuildCommand::class,
                RelevanceIndexSwapCommand::class,
                RelevanceExplainCommand::class,
                \Relevance\Console\RelevanceRecTrainCommand::class,
                \Relevance\Console\RelevanceEvalCommand::class,
                \Relevance\Console\RelevanceBenchmarkCommand::class,
                \Relevance\Console\RelevanceArtifactActivateCommand::class,
                \Relevance\Console\RelevanceInstallCommand::class,
                \Relevance\Console\RelevanceIndexMakeCommand::class,
                \Relevance\Console\RelevancePruneCommand::class,
                \Relevance\Console\RelevancePrivacyEraseCommand::class,
            ]);
        }

        $this->app->make(IndexRegistry::class)->registerObservers();
    }
}
