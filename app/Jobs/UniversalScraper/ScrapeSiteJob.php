<?php

namespace App\Jobs\UniversalScraper;

use App\Jobs\UniversalScraper\Concerns\HandlesScraperFailures;
use App\Models\ScraperSite;
use App\Services\UniversalScraper\ScraperRepository;
use App\Services\UniversalScraper\UniversalScraperPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScrapeSiteJob implements ShouldQueue
{
    use HandlesScraperFailures;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        public readonly string $siteKey,
        public readonly ?string $llmDevice = null,
    ) {
        $this->onQueue((string) config('universal_scraper.queue.scrape_site', 'scrape_site'));
        $this->timeout = (int) config('universal_scraper.queue.scrape_site_timeout', 1800);
    }

    public function handle(UniversalScraperPipeline $pipeline, ScraperRepository $repository): void
    {
        $repository->setProcessing($this->siteKey);
        $run = $repository->startScrapeRun($this->siteKey);
        $site = ScraperSite::query()->where('site_key', $this->siteKey)->first();

        if (!$site) {
            $repository->finishScrapeRun($run, 'failed', errorMessage: 'Site not found.');

            return;
        }

        try {
            $result = $pipeline->scrape($site, $this->llmDevice);
            $jobs = $result['jobs'];
            $pagesScanned = $result['pages_scanned'];

            if ($jobs !== []) {
                $repository->upsertVacancies($this->siteKey, $jobs);
                $repository->setSuccess($this->siteKey, intervalDays: 1);
                $repository->finishScrapeRun($run, 'done', pagesScanned: $pagesScanned, jobsFound: count($jobs), jobsUpserted: count($jobs));

                return;
            }

            $repository->incrementEmptyRuns($this->siteKey, intervalHours: 24);
            $repository->finishScrapeRun($run, 'done', pagesScanned: $pagesScanned, jobsFound: 0, jobsUpserted: 0);
        } catch (Throwable $exception) {
            Log::warning('Site scrape failed', [
                'site_key' => $this->siteKey,
                'error' => $exception->getMessage(),
            ]);
            $this->handleSiteError($repository, $this->siteKey, $exception, 6);
            $repository->finishScrapeRun($run, 'failed', errorMessage: $exception->getMessage());
        }
    }
}
