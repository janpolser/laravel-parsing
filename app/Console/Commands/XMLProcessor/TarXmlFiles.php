<?php

namespace App\Console\Commands\XMLProcessor;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TarXmlFiles extends Command
{
    protected $signature = 'xml:tar {source? : Optional source folder to process}';

    protected $description = 'Создает TAR архив из XML файлов. Новый архив кладется в latest, старый переносится выше';

    public function handle()
    {
        ini_set('memory_limit', '4G');
        $sources = [
            '5ka' => 'storage/app/public/5ka',
            'kuper' => 'storage/app/public/kuper',
            'magnit' => 'storage/app/public/magnit',
            'rzhd' => 'storage/app/public/rzhd',
            'wb' => 'storage/app/public/wb',
            'yandex' => 'storage/app/public/yandex',
            'gossluzhba' => 'storage/app/public/gossluzhba',
        ];

        $source = $this->argument('source');
        if ($source !== null) {
            $source = (string) $source;
            if (!isset($sources[$source])) {
                $this->error("Unknown source: {$source}");

                return 1;
            }

            $this->process($sources[$source], $source);

            return 0;
        }

        foreach ($sources as $prefix => $folder) {
            $this->process($folder, $prefix);
        }

        return 0;
    }

    private function process(string $folder, string $prefix)
    {
        if (!File::exists($folder)) {
            $this->error("Папка {$folder} не существует!");

            return 1;
        }

        $latestDir = $folder . '/latest';

        // Создаем папку latest, если ее нет
        if (!File::exists($latestDir)) {
            File::makeDirectory($latestDir, 0755, true);
        }

        // Получаем XML файлы
        $xmlFiles = File::glob($folder . '/*.xml');

        if (empty($xmlFiles)) {
            $this->info("В папке {$folder} нет XML файлов.");

            return 0;
        }

        $this->info('Найдено ' . count($xmlFiles) . " XML файлов в {$folder}");

        // Переносим старые архивы из latest на уровень выше только когда есть новый XML
        $oldArchives = File::glob($latestDir . '/*.tar');
        foreach ($oldArchives as $oldArchive) {
            $destination = $folder . '/' . basename($oldArchive);
            File::move($oldArchive, $destination);
            $this->line('Перенесен старый архив: ' . basename($oldArchive));
        }

        $tarFileName = $prefix . now('Europe/Moscow')->format('Y-m-d_His') . '.tar';
        $tarPath = $latestDir . '/' . $tarFileName;

        $tarHandle = fopen($tarPath, 'x+');

        foreach ($xmlFiles as $xmlFile) {
            $fileName = basename($xmlFile);
            $fileSize = filesize($xmlFile);
            $fileContent = file_get_contents($xmlFile);

            $header = $this->createTarHeader($fileName, $fileSize);

            fwrite($tarHandle, $header);
            fwrite($tarHandle, $fileContent);

            $padding = 512 - ($fileSize % 512);
            if ($padding < 512) {
                fwrite($tarHandle, str_repeat("\0", $padding));
            }

            $this->line("Добавлен: {$fileName} ({$fileSize} байт)");
        }

        // Конец архива
        fwrite($tarHandle, str_repeat("\0", 1024));
        fclose($tarHandle);

        // Удаляем XML файлы
        foreach ($xmlFiles as $xmlFile) {
            File::delete($xmlFile);
            $this->line('Удален: ' . basename($xmlFile));
        }

        $this->info("✓ TAR архив создан: {$tarPath}");
        $this->info('✓ Исходные XML удалены');
        $this->info('✓ Размер архива: ' . filesize($tarPath) . ' байт');

        return 0;
    }

    private function createTarHeader(string $filename, int $size): string
    {
        if (strlen($filename) > 100) {
            $filename = substr($filename, -100);
        }

        $header = str_pad($filename, 100, "\0");
        $header .= str_pad(decoct(0644), 7, '0', STR_PAD_LEFT) . "\0";
        $header .= str_pad(decoct(0), 7, '0', STR_PAD_LEFT) . "\0";
        $header .= str_pad(decoct(0), 7, '0', STR_PAD_LEFT) . "\0";
        $header .= str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . "\0";
        $header .= str_pad(decoct(time()), 11, '0', STR_PAD_LEFT) . "\0";
        $header .= '        ';
        $header .= '0';
        $header .= str_repeat("\0", 100);
        $header .= str_repeat("\0", 255);

        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }

        $checksumStr = str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT) . "\0 ";

        return substr_replace($header, $checksumStr, 148, 8);
    }
}
