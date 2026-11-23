<?php

require __DIR__ . '/../VolumeRules.php';

use XS\ThreadVolume\VolumeRules;

$checks = [
    '39 visible posts' => [VolumeRules::reachedLimit(38), false],
    '40 visible posts' => [VolumeRules::reachedLimit(39), true],
    'historical volume over limit' => [VolumeRules::reachedLimit(1600), true],
    'first rollover' => [VolumeRules::titles('Название'), ['Название том 1', 'Название том 2']],
    'modern volume' => [VolumeRules::titles('Название том 2'), ['Название том 2', 'Название том 3']],
    'legacy volume' => [VolumeRules::titles('Вопросы и ответы по форуму т.3'), ['Вопросы и ответы по форуму т.3', 'Вопросы и ответы по форуму том 4']],
    'number inside title' => [VolumeRules::titles('Тема т.3 из архива'), ['Тема т.3 из архива том 1', 'Тема т.3 из архива том 2']],
];
$errors = 0;
foreach ($checks as $name => [$actual, $expected])
{
    if ($actual !== $expected)
    {
        fwrite(STDERR, "Failed: $name\n");
        $errors++;
    }
}
printf("%d checks, %d failures\n", count($checks), $errors);
exit($errors ? 1 : 0);
