<?php

namespace App\Console\Commands\UniversalScraper;

use App\Services\UniversalScraper\FeedXmlRenderer;
use Illuminate\Console\Command;

class BuildFeedXml extends Command
{
    protected $signature = 'scraper:build-feed
        {--output= : Output path inside selected disk}
        {--disk= : Filesystem disk}
        {--limit= : Max vacancies, 0 means no limit}';

    protected $description = 'Builds XML feed file from active universal scraper vacancies.';

    public function handle(FeedXmlRenderer $renderer): int
    {
        $limit = $this->option('limit');
        $count = $renderer->writeFile(
            $this->option('output') ? (string) $this->option('output') : null,
            $limit !== null && $limit !== '' ? (int) $limit : null,
            $this->option('disk') ? (string) $this->option('disk') : null,
        );

        $this->info("Feed built. Vacancies: {$count}");

        return self::SUCCESS;
    }
}
