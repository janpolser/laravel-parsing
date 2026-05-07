<?php

namespace App\Console\Commands\UniversalScraper;

use App\Services\UniversalScraper\SiteImporter;
use Illuminate\Console\Command;

class ImportSites extends Command
{
    protected $signature = 'scraper:import-sites
        {file : TXT/CSV/JSONL file path}
        {--format=txt : txt, csv or jsonl}
        {--url-column=website : CSV column / JSONL field with URL}
        {--overwrite-location : Overwrite existing contacts/location fields}';

    protected $description = 'Imports company sites for the universal scraper.';

    public function handle(SiteImporter $importer): int
    {
        $file = (string) $this->argument('file');
        $format = (string) $this->option('format');
        $urlColumn = (string) $this->option('url-column');

        if (!is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $entries = match ($format) {
            'txt' => $importer->readTxt($file),
            'csv' => $importer->readCsv($file, $urlColumn),
            'jsonl' => $importer->readJsonLines($file, $urlColumn),
            default => null,
        };

        if ($entries === null) {
            $this->error('Unsupported format. Use txt, csv or jsonl.');

            return self::FAILURE;
        }

        $count = $importer->import($entries, (bool) $this->option('overwrite-location'));
        $this->info("Imported {$count} sites.");

        return self::SUCCESS;
    }
}
