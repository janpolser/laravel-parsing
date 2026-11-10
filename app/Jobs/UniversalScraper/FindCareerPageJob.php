<?php

namespace App\Jobs\UniversalScraper;

use App\Jobs\UniversalScraper\Concerns\HandlesScraperFailures;
use App\Models\ScraperSite;
use App\Services\UniversalScraper\CareerPageFinder;
use App\Services\UniversalScraper\ScraperRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FindCareerPageJob implements ShouldQueue
{
    use HandlesScraperFailures;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public readonly string $siteKey,
        public readonly ?bool $chainScrape = null,
        public readonly ?string $llmDevice = null,
    ) {
        $this->onQueue((string) config('universal_scraper.queue.find_career', 'find_career'));
    }

    public function handle(CareerPageFinder $finder, ScraperRepository $repository): void
    {
        $repository->setProcessing($this->siteKey);

        $site = ScraperSite::query()->where('site_key', $this->siteKey)->first();

        if (!$site || !$site->base_url) {
            $repository->setFailure($this->siteKey, intervalHours: 24, incrementFail: false);

            return;
        }

        try {
            $result = $finder->find($site->base_url, useLlm: true, llmDevice: $this->llmDevice);
        } catch (Throwable $exception) {
            Log::warning('Career page finder failed', [
                'site_key' => $this->siteKey,
                'error' => $exception->getMessage(),
            ]);
            $this->handleSiteError($repository, $this->siteKey, $exception, 24);

            return;
        }

        if ($result['too_big']) {
            $repository->setCareerUrl($this->siteKey, null);
            $repository->setParseStatus($this->siteKey, 'career_page_too_big');
            $repository->setFailure($this->siteKey, intervalHours: 24 * 7, incrementFail: false);

            return;
        }

        if ($result['url']) {
            $repository->setCareerUrl($this->siteKey, $result['url']);

            if ($this->chainScrape ?? true) {
                ScrapeSiteJob::dispatch($this->siteKey, $this->llmDevice);
            } else {
                $repository->setCareerFound($this->siteKey, intervalHours: 24);
            }

            return;
        }

        $repository->setCareerUrl($this->siteKey, null);
        $repository->setParseStatus($this->siteKey, 'no_career_page');
        $repository->setDisabled($this->siteKey);
    }
}
