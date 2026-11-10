<?php

namespace App\Providers;

use App\Support\FeedRequestPacer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FeedRequestPacer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            ini_set('memory_limit', env('CONSOLE_MEMORY_LIMIT', '512M'));

            $pacer = $this->app->make(FeedRequestPacer::class);
            Event::listen(CommandStarting::class, static fn (CommandStarting $event) => $pacer->enter($event->command));
            Event::listen(CommandFinished::class, static fn () => $pacer->leave());
            Http::globalMiddleware(static fn (callable $handler) => static function ($request, array $options) use ($handler, $pacer) {
                if (config('feed_request_pacing.enabled', true)) {
                    $pacer->waitForCurrentGroup();
                }

                return $handler($request, $options);
            });
        }
    }
}
