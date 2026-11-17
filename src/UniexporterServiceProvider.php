<?php

namespace Wovosoft\LaravelUniexporter;

use Illuminate\Support\ServiceProvider;

class UniexporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/uniexporter.php', 'uniexporter');

        $this->app->singleton(UniexporterManager::class, function ($app): UniexporterManager {
            $config = $app['config']->get('uniexporter');
            $config['temp_dir'] = $config['temp_dir'] ?: storage_path('app/uniexporter');

            return new UniexporterManager($config);
        });

        $this->app->alias(UniexporterManager::class, 'uniexporter');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/uniexporter.php' => config_path('uniexporter.php'),
            ], 'uniexporter.config');

            // The AI agent skill, for projects without Laravel Boost (Boost discovers resources/boost itself).
            $this->publishes([
                __DIR__.'/../resources/boost/skills/laravel-uniexporter' => base_path('.claude/skills/laravel-uniexporter'),
            ], 'uniexporter.skills');
        }
    }
}
