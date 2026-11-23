<?php

declare(strict_types=1);

const SHIP = ['addon.json', 'Setup.php', 'Config.php', 'VolumeRules.php', '_data', 'Job', 'Service', 'XF'];
const SOURCE_ONLY = ['.git', '.github', '.gitignore', 'README.md', 'build.php', 'tests', '_releases'];
const XF_PATH = 'src/addons/XS/ThreadVolume/';

try
{
    if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--dry-run'))
    {
        throw new RuntimeException('Usage: php build.php [--dry-run]');
    }
    $dryRun = isset($argv[1]);
    $root = __DIR__;
    foreach (new FilesystemIterator($root) as $entry)
    {
        $name = $entry->getFilename();
        if (!in_array($name, SHIP, true) && !in_array($name, SOURCE_ONLY, true))
        {
            throw new RuntimeException("Unclassified package entry: $name");
        }
    }

    $files = [];
    foreach (SHIP as $relative)
    {
        $path = "$root/$relative";
        if (is_link($path) || !file_exists($path))
        {
            throw new RuntimeException("Invalid package entry: $relative");
        }
        if (is_file($path))
        {
            $files[] = $relative;
            continue;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($entries as $entry)
        {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            if ($entry->isLink() || str_starts_with($entry->getFilename(), '.'))
            {
                throw new RuntimeException("Invalid package entry: $relative");
            }
            if ($entry->isFile())
            {
                $files[] = $relative;
            }
        }
    }
    sort($files, SORT_STRING);

    $contents = $hashes = [];
    foreach ($files as $file)
    {
        $bytes = file_get_contents("$root/$file");
        if ($bytes === false)
        {
            throw new RuntimeException("Cannot read $file");
        }
        $contents[$file] = $bytes;
        $hashes[XF_PATH . $file] = hash('sha256', str_replace("\r", '', $bytes));
    }
    $addon = json_decode($contents['addon.json'], true, flags: JSON_THROW_ON_ERROR);
    $version = $addon['version_string'] ?? '';
    if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+(?:[-.][a-zA-Z0-9]+)*$/D', $version))
    {
        throw new RuntimeException('Invalid version_string in addon.json');
    }

    if ($dryRun)
    {
        foreach ($files as $file)
        {
            echo 'upload/' . XF_PATH . $file, PHP_EOL;
        }
        printf("Dry run: %d payload files and hashes.json\n", count($files));
        exit(0);
    }

    $destination = "$root/_releases";
    if (!is_dir($destination) && !mkdir($destination, 0755) && !is_dir($destination))
    {
        throw new RuntimeException("Cannot create $destination");
    }
    $archive = "$destination/XS-ThreadVolume-$version.zip";
    $temporary = tempnam($destination, '.build-');
    if ($temporary === false)
    {
        throw new RuntimeException("Cannot open temporary ZIP in $destination");
    }
    try
    {
        $zip = new ZipArchive();
        if ($zip->open($temporary, ZipArchive::OVERWRITE) !== true)
        {
            throw new RuntimeException('Cannot open ZIP for writing');
        }
        $contents['hashes.json'] = json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ($contents as $file => $bytes)
        {
            if (!$zip->addFromString('upload/' . XF_PATH . $file, $bytes))
            {
                throw new RuntimeException("Cannot add $file to ZIP");
            }
        }
        if (!$zip->close() || !rename($temporary, $archive))
        {
            throw new RuntimeException("Cannot finish $archive");
        }
    }
    finally
    {
        if (is_file($temporary))
        {
            unlink($temporary);
        }
    }
    printf("Built %s (%d entries)\n", $archive, count($contents));
}
catch (Throwable $error)
{
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(2);
}
