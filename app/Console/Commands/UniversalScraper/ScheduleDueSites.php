<?php

namespace App\Console\Commands\UniversalScraper;

use App\Jobs\UniversalScraper\FindCareerPageJob;
use App\Jobs\UniversalScraper\ScrapeSiteJob;
use App\Services\UniversalScraper\ScraperRepository;
use Illuminate\Console\Command;

class ScheduleDueSites extends Command
{
    protected $signature = 'scraper:schedule-due-sites
        {--limit=100 : Maximum sites to queue}
        {--scrape-only : Skip career page lookup and enqueue scrape jobs directly}
        {--llm-device= : auto, cpu or gpu}';

    protected $description = 'Queues due sites for universal vacancy scraping.';

    public function handle(ScraperRepository $repository): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sites = $repository->dueSites($limit);
        $device = $this->option('llm-device') ? (string) $this->option('llm-device') : null;

        foreach ($sites as $site) {
            $repository->markQueued($site);

            if ($this->option('scrape-only')) {
                ScrapeSiteJob::dispatch($site->site_key, $device);
            } else {
                FindCareerPageJob::dispatch($site->site_key, true, $device);
            }
        }

        $this->info('Queued sites: ' . $sites->count());

        return self::SUCCESS;
    }
}
