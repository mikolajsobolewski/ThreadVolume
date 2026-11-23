<?php

namespace XS\ThreadVolume;

final class VolumeRules
{
    public const MIN_REPLIES = 39;

    public static function reachedLimit(int $replyCount): bool
    {
        return $replyCount >= self::MIN_REPLIES;
    }

    public static function titles(string $title): array
    {
        if (preg_match('/^(.+?)\s+(?:том\s+|т\.\s*)([1-9][0-9]*)$/iuD', $title, $parts)
            && strlen($parts[2]) < 18)
        {
            return [$title, self::numberedTitle($parts[1], (int) $parts[2] + 1)];
        }

        return [self::numberedTitle($title, 1), self::numberedTitle($title, 2)];
    }

    private static function numberedTitle(string $title, int $volume): string
    {
        $suffix = ' том ' . $volume;
        return rtrim(mb_substr($title, 0, 150 - mb_strlen($suffix))) . $suffix;
    }
}
