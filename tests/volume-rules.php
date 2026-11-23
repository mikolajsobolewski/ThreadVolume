<?php

require __DIR__ . '/../Config.php';
require __DIR__ . '/../VolumeRules.php';

use XS\ThreadVolume\Config;
use XS\ThreadVolume\VolumeRules;

$custom = Config::from(['botUserId' => 17, 'postLimit' => 3]);
$checks = [
    'missing config disables rollover' => [Config::from(null)->botUserId, 0],
    'invalid limit disables rollover' => [Config::from(['botUserId' => 17, 'postLimit' => 1])->botUserId, 0],
    'configured bot ID' => [$custom->botUserId, 17],
    'default post limit' => [Config::from(['botUserId' => 17])->postLimit, 40],
    'two posts below configured limit' => [VolumeRules::reachedLimit(1, $custom->postLimit), false],
    'three posts reach configured limit' => [VolumeRules::reachedLimit(2, $custom->postLimit), true],
    '39 visible posts' => [VolumeRules::reachedLimit(38), false],
    '40 visible posts' => [VolumeRules::reachedLimit(39), true],
    'historical volume over limit' => [VolumeRules::reachedLimit(1600), true],
    'first rollover' => [VolumeRules::titles('Название'), ['Название том 1', 'Название том 2']],
    'modern volume' => [VolumeRules::titles('Название том 2'), ['Название том 2', 'Название том 3']],
    'legacy volume' => [VolumeRules::titles('Вопросы и ответы по форуму т.3'), ['Вопросы и ответы по форуму т.3', 'Вопросы и ответы по форуму том 4']],
    'parenthesized legacy volume' => [VolumeRules::titles('Сиськи! Фото (т. 2)'), ['Сиськи! Фото (т. 2)', 'Сиськи! Фото том 3']],
    'parenthesized full volume' => [VolumeRules::titles('Название (Том 2)'), ['Название (Том 2)', 'Название том 3']],
    'legacy abbreviation without a dot' => [VolumeRules::titles('Название т 2'), ['Название т 2', 'Название том 3']],
    'unmatched parenthesis' => [VolumeRules::titles('Название т. 2)'), ['Название т. 2) том 1', 'Название т. 2) том 2']],
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
