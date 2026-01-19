<?php

namespace App\Console\Commands\XMLProcessor;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TarXmlFiles extends Command
{
    protected $signature = 'xml:tar';

    protected $description = 'Создает TAR архив из XML файлов и удаляет исходные файлы';

    public function handle()
    {
        $this->process('storage/app/public/5ka');
        $this->process('storage/app/public/kuper');
        $this->process('storage/app/public/magnit');
        $this->process('storage/app/public/rzhd');
        $this->process('storage/app/public/wb');
        $this->process('storage/app/public/yandex');
    }

    private function process(string $path)
    {
        $folder = $path;

        // Проверяем существование папки
        if (!File::exists($folder)) {
            $this->error("Папка {$folder} не существует!");

            return 1;
        }

        // Получаем все XML файлы
        $xmlFiles = File::glob($folder . '/*.xml');

        if (empty($xmlFiles)) {
            $this->info("В папке {$folder} не найдено XML файлов.");

            return 0;
        }

        $this->info('Найдено ' . count($xmlFiles) . ' XML файлов.');

        // Создаем TAR файл
        $tarFileName = 'xml_files_' . date('Y-m-d_His') . '.tar';
        $tarPath = $folder . '/' . $tarFileName;

        // Открываем tar файл для записи
        $tarHandle = fopen($tarPath, 'x+');

        foreach ($xmlFiles as $xmlFile) {
            $fileName = basename($xmlFile);
            $fileContent = file_get_contents($xmlFile);
            $fileSize = filesize($xmlFile);

            // Формируем tar header (512 байт)
            $header = $this->createTarHeader($fileName, $fileSize);

            // Пишем заголовок
            fwrite($tarHandle, $header);

            // Пишем содержимое файла
            fwrite($tarHandle, $fileContent);

            // Дополняем до 512 байт
            $padding = 512 - ($fileSize % 512);
            if ($padding < 512) {
                fwrite($tarHandle, str_repeat("\0", $padding));
            }

            $this->line("Добавлен: {$fileName} ({$fileSize} байт)");
        }

        fwrite($tarHandle, str_repeat("\0", 1024));
        fclose($tarHandle);

        foreach ($xmlFiles as $xmlFile) {
            File::delete($xmlFile);
            $this->line('Удален: ' . basename($xmlFile));
        }

        $this->info("✓ TAR архив создан: {$tarPath}");
        $this->info('✓ Исходные файлы удалены.');
        $this->info('✓ Размер архива: ' . filesize($tarPath) . ' байт');

        return 0;
    }

    private function createTarHeader(string $filename, int $size): string
    {
        // Ограничиваем имя файла 100 символами
        if (strlen($filename) > 100) {
            $filename = substr($filename, -100);
        }

        $header = str_pad($filename, 100, "\0");                    // имя файла (100 байт)
        $header .= str_pad(decoct(0644), 7, '0', STR_PAD_LEFT) . "\0"; // режим доступа (8 байт)
        $header .= str_pad(decoct(0), 7, '0', STR_PAD_LEFT) . "\0";    // uid владельца (8 байт)
        $header .= str_pad(decoct(0), 7, '0', STR_PAD_LEFT) . "\0";    // gid группы (8 байт)
        $header .= str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . "\0"; // размер файла (12 байт)
        $header .= str_pad(decoct(time()), 11, '0', STR_PAD_LEFT) . "\0"; // время модификации (12 байт)
        $header .= '        ';                               // место для checksum (8 байт)

        // Тип файла (0 - обычный файл)
        $header .= '0';

        // Ссылка на файл (100 байт)
        $header .= str_repeat("\0", 100);

        // Дополняем до 512 байт
        $header .= str_repeat("\0", 255);

        // Вычисляем checksum
        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }

        // Вставляем checksum (восьмеричное значение)
        $checksumStr = str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT) . "\0 ";

        return substr_replace($header, $checksumStr, 148, 8);
    }
}
