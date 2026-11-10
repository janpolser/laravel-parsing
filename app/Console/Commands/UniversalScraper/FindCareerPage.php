<?php

namespace App\Console\Commands\UniversalScraper;

use App\Jobs\UniversalScraper\FindCareerPageJob;
use Illuminate\Console\Command;

class FindCareerPage extends Command
{
    protected $signature = 'scraper:find-career
        {site_key : Site key}
        {--sync : Run immediately instead of queueing}
        {--no-chain : Do not enqueue scraping after finding career page}
        {--llm-device= : auto, cpu or gpu}';

    protected $description = 'Finds and stores career page URL for one site.';

    public function handle(): int
    {
        $job = new FindCareerPageJob(
            (string) $this->argument('site_key'),
            !$this->option('no-chain'),
            $this->option('llm-device') ? (string) $this->option('llm-device') : null,
        );

        if ($this->option('sync')) {
            app()->call([$job, 'handle']);
            $this->info('Career lookup finished.');
        } else {
            dispatch($job);
            $this->info('Career lookup queued.');
        }

        return self::SUCCESS;
    }
}
