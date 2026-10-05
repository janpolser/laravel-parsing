<?php

use App\Console\Commands\XMLProcessor\TarXmlFiles;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(Tests\TestCase::class);

test('tar validation accepts an archive containing every expected XML entry', function () {
    $command = new TarXmlFiles();
    $header = new ReflectionMethod($command, 'createTarHeader');
    $header->setAccessible(true);
    $validate = new ReflectionMethod($command, 'assertArchiveIsComplete');
    $validate->setAccessible(true);

    $content = '<vacancies><vacancy/></vacancies>';
    $path = sys_get_temp_dir() . '/tar-xml-' . bin2hex(random_bytes(8)) . '.tar';

    try {
        file_put_contents(
            $path,
            $header->invoke($command, 'feed.xml', strlen($content))
            . $content
            . str_repeat("\0", (512 - (strlen($content) % 512)) % 512)
            . str_repeat("\0", 1024)
        );

        $validate->invoke($command, $path, ['feed.xml' => strlen($content)]);

        expect(true)->toBeTrue();
    } finally {
        @unlink($path);
    }
});

test('tar validation rejects an incomplete archive', function () {
    $command = new TarXmlFiles();
    $validate = new ReflectionMethod($command, 'assertArchiveIsComplete');
    $validate->setAccessible(true);
    $path = sys_get_temp_dir() . '/tar-xml-' . bin2hex(random_bytes(8)) . '.tar';

    try {
        file_put_contents($path, 'broken');

        expect(fn () => $validate->invoke($command, $path, ['feed.xml' => 1]))
            ->toThrow(RuntimeException::class);
    } finally {
        @unlink($path);
    }
});

test('tar publication keeps the old archive until a validated replacement is published', function () {
    $folder = storage_path('framework/testing/tar-xml-' . bin2hex(random_bytes(8)));
    $latest = $folder . '/latest';
    $oldArchive = $latest . '/test-old.tar';
    $xml = $folder . '/feed.xml';

    File::makeDirectory($latest, 0755, true);
    File::put($oldArchive, 'previous archive');
    touch($oldArchive, time() - 60);
    File::put($xml, '<vacancies><vacancy/></vacancies>');

    $command = new TarXmlFiles();
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
    $process = new ReflectionMethod($command, 'process');
    $process->setAccessible(true);

    try {
        expect($process->invoke($command, $folder, 'test'))->toBe(TarXmlFiles::SUCCESS);

        $published = File::glob($latest . '/*.tar');
        expect($published)->toHaveCount(1);
        expect(basename($published[0]))->not->toBe('test-old.tar');
        expect(File::exists($folder . '/test-old.tar'))->toBeTrue();
        expect(File::exists($xml))->toBeFalse();

        $archive = new PharData($published[0]);
        expect(isset($archive['feed.xml']))->toBeTrue();
    } finally {
        File::deleteDirectory($folder);
    }
});
