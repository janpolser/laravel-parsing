<?php

namespace App\Services\UniversalScraper;

use App\Models\ScrapeRun;
use App\Models\ScraperSite;
use App\Models\ScraperVacancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScraperRepository
{
    /**
     * @return Collection<int, ScraperSite>
     */
    public function dueSites(int $limit = 100): Collection
    {
        return ScraperSite::query()
            ->whereIn('status', ['ready', 'error'])
            ->where(fn ($query) => $query->whereNull('next_scrape_at')->orWhere('next_scrape_at', '<=', now()))
            ->orderBy('next_scrape_at')
            ->limit($limit)
            ->get();
    }

    public function markQueued(ScraperSite $site): void
    {
        $site->forceFill(['status' => 'queued'])->save();
    }

    public function setProcessing(string $siteKey): void
    {
        ScraperSite::query()->where('site_key', $siteKey)->update([
            'status' => 'processing',
            'updated_at' => now(),
        ]);
    }

    public function setDisabled(string $siteKey): void
    {
        ScraperSite::query()->where('site_key', $siteKey)->update([
            'status' => 'disabled',
            'last_scraped_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function setCareerUrl(string $siteKey, ?string $careerUrl): void
    {
        $pathOnly = app(UrlTools::class)->careerPathOnly($careerUrl);

        ScraperSite::query()->where('site_key', $siteKey)->update([
            'career_url' => $pathOnly,
            'updated_at' => now(),
        ]);
    }

    public function setParseStatus(string $siteKey, string $parseStatus): void
    {
        ScraperSite::query()->where('site_key', $siteKey)->update([
            'parse_status' => $parseStatus,
            'updated_at' => now(),
        ]);
    }

    public function setSuccess(string $siteKey, int $intervalDays = 1): void
    {
        ScraperSite::query()->where('site_key', $siteKey)->update([
            'status' => 'ready',
            'last_scraped_at' => now(),
            'next_scrape_at' => now()->addDays($intervalDays),
            'fail_count' => 0,
            'empty_runs' => 0,
            'parse_status' => 'parseable',
            'updated_at' => now(),
        ]);
    }

    public function setCareerFound(string $siteKey, int $intervalHours = 24): void
    {
        ScraperSite::query()->where('site_key', $siteKey)->update([
            'status' => 'ready',
            'next_scrape_at' => now()->addHours($intervalHours),
            'fail_count' => 0,
            'parse_status' => 'parseable',
            'updated_at' => now(),
        ]);
    }

    public function setFailure(string $siteKey, int $intervalHours = 6, ?int $httpStatus = null, bool $incrementFail = true): int
    {
        return DB::transaction(function () use ($siteKey, $intervalHours, $httpStatus, $incrementFail) {
            /** @var ScraperSite|null $site */
            $site = ScraperSite::query()->where('site_key', $siteKey)->lockForUpdate()->first();

            if (!$site) {
                return 0;
            }

            $site->status = 'error';
            $site->last_scraped_at = now();
            $site->next_scrape_at = now()->addHours($intervalHours);
            $site->last_http_status = $httpStatus ?? $site->last_http_status;
            $site->fail_count = $incrementFail ? $site->fail_count + 1 : $site->fail_count;
            $site->updated_at = now();
            $site->save();

            return $site->fail_count;
        });
    }

    public function incrementEmptyRuns(string $siteKey, int $intervalHours = 24): int
    {
        return DB::transaction(function () use ($siteKey, $intervalHours) {
            /** @var ScraperSite|null $site */
            $site = ScraperSite::query()->where('site_key', $siteKey)->lockForUpdate()->first();

            if (!$site) {
                return 0;
            }

            $site->empty_runs++;
            $site->last_scraped_at = now();
            $site->next_scrape_at = now()->addHours($site->empty_runs >= 3 ? 168 : $intervalHours);
            $site->status = 'ready';

            if ($site->empty_runs >= 3) {
                $site->parse_status = 'no_vacancies';
            }

            $site->updated_at = now();
            $site->save();

            return $site->empty_runs;
        });
    }

    public function startScrapeRun(string $siteKey): ?ScrapeRun
    {
        $site = ScraperSite::query()->where('site_key', $siteKey)->first();

        if (!$site) {
            return null;
        }

        return ScrapeRun::query()->create([
            'id' => (string) Str::uuid(),
            'site_id' => $site->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function finishScrapeRun(
        ?ScrapeRun $run,
        string $status,
        int $pagesScanned = 0,
        int $jobsFound = 0,
        int $jobsUpserted = 0,
        ?string $errorMessage = null,
    ): void {
        if (!$run) {
            return;
        }

        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'pages_scanned' => $pagesScanned,
            'jobs_found' => $jobsFound,
            'jobs_upserted' => $jobsUpserted,
            'error_message' => $errorMessage,
        ])->save();
    }

    /**
     * @param list<array<string, mixed>> $vacancies
     */
    public function upsertVacancies(string $siteKey, array $vacancies): void
    {
        DB::transaction(function () use ($siteKey, $vacancies) {
            /** @var ScraperSite $site */
            $site = ScraperSite::query()->firstOrCreate(['site_key' => $siteKey]);
            $seenHashes = [];

            foreach ($vacancies as $vacancy) {
                $contentHash = $this->contentHash($vacancy);
                $seenHashes[] = $contentHash;

                $payload = [
                    'site_id' => $site->id,
                    'title' => (string) $vacancy['title'],
                    'company' => $vacancy['company'] ?? null,
                    'location' => $vacancy['location'] ?? null,
                    'description' => (string) ($vacancy['description'] ?? ''),
                    'contacts' => $vacancy['contacts'] ?? [],
                    'salary_value' => $vacancy['salary_value'] ?? null,
                    'salary_currency' => $vacancy['salary_currency'] ?? null,
                    'job_type' => $vacancy['job_type'] ?? null,
                    'level' => $vacancy['level'] ?? null,
                    'skills' => $vacancy['skills'] ?? [],
                    'posted_at' => $vacancy['posted_at'] ?? null,
                    'source_url' => $vacancy['source_url'] ?? null,
                    'scraped_at' => $vacancy['scraped_at'] ?? Carbon::now(),
                    'last_seen_at' => $vacancy['scraped_at'] ?? Carbon::now(),
                    'content_hash' => $contentHash,
                    'is_active' => true,
                ];

                if (!empty($payload['source_url'])) {
                    ScraperVacancy::query()->updateOrCreate(
                        ['site_id' => $site->id, 'source_url' => $payload['source_url']],
                        $payload,
                    );
                } else {
                    ScraperVacancy::query()->create($payload);
                }
            }

            $query = ScraperVacancy::query()->where('site_id', $site->id)->where('is_active', true);

            if ($seenHashes !== []) {
                $query->whereNotIn('content_hash', $seenHashes)->update(['is_active' => false]);
            } else {
                $query->update(['is_active' => false]);
            }
        });
    }

    /**
     * @param array<string, mixed> $vacancy
     */
    private function contentHash(array $vacancy): string
    {
        return hash('sha256', json_encode([
            'title' => $vacancy['title'] ?? null,
            'company' => $vacancy['company'] ?? null,
            'location' => $vacancy['location'] ?? null,
            'description' => $vacancy['description'] ?? null,
            'contacts' => $vacancy['contacts'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
