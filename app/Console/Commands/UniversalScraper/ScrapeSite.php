<?php

namespace App\Console\Commands\UniversalScraper;

use App\Jobs\UniversalScraper\ScrapeSiteJob;
use Illuminate\Console\Command;

class ScrapeSite extends Command
{
    protected $signature = 'scraper:scrape-site
        {site_key : Site key}
        {--sync : Run immediately instead of queueing}
        {--llm-device= : auto, cpu or gpu}';

    protected $description = 'Scrapes vacancies for one configured site.';

    public function handle(): int
    {
        $job = new ScrapeSiteJob(
            (string) $this->argument('site_key'),
            $this->option('llm-device') ? (string) $this->option('llm-device') : null,
        );

        if ($this->option('sync')) {
            app()->call([$job, 'handle']);
            $this->info('Site scrape finished.');
        } else {
            dispatch($job);
            $this->info('Site scrape queued.');
        }

        return self::SUCCESS;
    }
}
