<?php

namespace App\Console\Commands\XMLProcessor;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TarXmlFiles extends Command
{
    protected $signature = 'xml:tar {source? : Optional source folder to process}';

    protected $description = 'Создает и атомарно публикует TAR из XML файлов.';

    public function handle(): int
    {
        ini_set('memory_limit', '4G');
        $sources = [
            '5ka' => 'storage/app/public/5ka',
            'hirehi' => 'storage/app/public/hirehi',
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

            return $this->process($sources[$source], $source);
        }

        $exitCode = self::SUCCESS;
        foreach ($sources as $prefix => $folder) {
            if ($this->process($folder, $prefix) !== self::SUCCESS) {
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    private function process(string $folder, string $prefix): int
    {
        if (!File::exists($folder)) {
            $this->error("Папка {$folder} не существует!");

            return self::FAILURE;
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

            return self::SUCCESS;
        }

        $this->info('Найдено ' . count($xmlFiles) . " XML файлов в {$folder}");

        // Existing archives must remain downloadable until the new one is complete.
        $oldArchives = File::glob($latestDir . '/*.tar');

        $tarFileName = $prefix . now('Europe/Moscow')->format('Y-m-d_His') . '.tar';
        $tarPath = $latestDir . '/' . $tarFileName;
        $tmpTarPath = $latestDir . '/.' . $tarFileName . '.tmp';
        $tarHandle = null;
        $expectedEntries = [];

        try {
            if (File::exists($tmpTarPath)) {
                File::delete($tmpTarPath);
            }

            $tarHandle = fopen($tmpTarPath, 'x+b');
            if ($tarHandle === false) {
                throw new \RuntimeException("Не удалось создать временный TAR: {$tmpTarPath}");
            }

            foreach ($xmlFiles as $xmlFile) {
                $fileName = basename($xmlFile);
                $fileSize = filesize($xmlFile);
                if ($fileSize === false) {
                    throw new \RuntimeException("Не удалось определить размер XML: {$fileName}");
                }

                $this->addFileToTar($tarHandle, $xmlFile, $fileName, $fileSize);
                $expectedEntries[$fileName] = $fileSize;
                $this->line("Добавлен во временный TAR: {$fileName} ({$fileSize} байт)");
            }

            $this->writeAll($tarHandle, str_repeat("\0", 1024));
            if (!fflush($tarHandle)) {
                throw new \RuntimeException('Не удалось записать TAR на диск.');
            }
            fclose($tarHandle);
            $tarHandle = null;

            $this->assertArchiveIsComplete($tmpTarPath, $expectedEntries);

            if (!File::move($tmpTarPath, $tarPath)) {
                throw new \RuntimeException("Не удалось опубликовать TAR: {$tarPath}");
            }

        } catch (\Throwable $e) {
            if (is_resource($tarHandle)) {
                fclose($tarHandle);
            }
            if (File::exists($tmpTarPath)) {
                File::delete($tmpTarPath);
            }

            $this->error('Новый TAR не опубликован; предыдущий архив остаётся доступен: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($oldArchives as $oldArchive) {
            if (!File::exists($oldArchive)) {
                continue;
            }

            $destination = $folder . '/' . basename($oldArchive);
            if (File::exists($destination)) {
                $this->warn('Старый архив уже есть в истории, оставлен в latest: ' . basename($oldArchive));

                continue;
            }

            if (File::move($oldArchive, $destination)) {
                $this->line('Перенесен старый архив: ' . basename($oldArchive));
            } else {
                $this->warn('Не удалось перенести старый архив, он сохранён в latest: ' . basename($oldArchive));
            }
        }

        foreach ($xmlFiles as $xmlFile) {
            if (File::delete($xmlFile)) {
                $this->line('Удален: ' . basename($xmlFile));
            } else {
                $this->warn('Не удалось удалить XML, он сохранён для следующего запуска: ' . basename($xmlFile));
            }
        }

        $this->info("✓ TAR архив создан: {$tarPath}");
        $this->info('✓ Новый TAR опубликован после проверки содержимого');
        $this->info('✓ Исходные XML удалены');
        $this->info('✓ Размер архива: ' . filesize($tarPath) . ' байт');

        return self::SUCCESS;
    }

    private function addFileToTar($tarHandle, string $sourcePath, string $fileName, int $fileSize): void
    {
        $this->writeAll($tarHandle, $this->createTarHeader($fileName, $fileSize));

        $sourceHandle = fopen($sourcePath, 'rb');
        if ($sourceHandle === false) {
            throw new \RuntimeException("Не удалось открыть XML: {$fileName}");
        }

        try {
            while (!feof($sourceHandle)) {
                $chunk = fread($sourceHandle, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException("Не удалось прочитать XML: {$fileName}");
                }
                if ($chunk !== '') {
                    $this->writeAll($tarHandle, $chunk);
                }
            }
        } finally {
            fclose($sourceHandle);
        }

        $padding = (512 - ($fileSize % 512)) % 512;
        if ($padding > 0) {
            $this->writeAll($tarHandle, str_repeat("\0", $padding));
        }
    }

    private function writeAll($handle, string $content): void
    {
        $length = strlen($content);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Не удалось записать TAR на диск.');
            }
            $offset += $written;
        }
    }

    /**
     * @param array<string, int> $expectedEntries
     */
    private function assertArchiveIsComplete(string $tarPath, array $expectedEntries): void
    {
        if (!is_file($tarPath) || filesize($tarPath) < 1024) {
            throw new \RuntimeException('Временный TAR пуст или не создан.');
        }

        try {
            $archive = new \PharData($tarPath);
            if (count($archive) !== count($expectedEntries)) {
                throw new \RuntimeException('Временный TAR содержит неполный набор XML.');
            }

            foreach ($expectedEntries as $fileName => $fileSize) {
                if (!isset($archive[$fileName]) || $archive[$fileName]->getSize() !== $fileSize) {
                    throw new \RuntimeException("Временный TAR не содержит корректный XML: {$fileName}");
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException) {
                throw $e;
            }

            throw new \RuntimeException('Временный TAR не прошёл проверку: ' . $e->getMessage(), previous: $e);
        }
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
