<?php

namespace XS\ThreadVolume;

final class VolumeRules
{
    public static function reachedLimit(int $replyCount, int $postLimit = Config::DEFAULT_POST_LIMIT): bool
    {
        return $replyCount >= $postLimit - 1;
    }

    public static function titles(string $title): array
    {
        // A legacy volume marker may be wrapped in a matching pair of parentheses.
        if (preg_match('/^(.+?)\s+(?:(\()\s*)?(?:том\s+|т(?:\.\s*|\s+))([1-9][0-9]*)(?(2)\s*\))$/iuD', $title, $parts)
            && strlen($parts[3]) < 18)
        {
            return [$title, self::numberedTitle($parts[1], (int) $parts[3] + 1)];
        }

        return [self::numberedTitle($title, 1), self::numberedTitle($title, 2)];
    }

    private static function numberedTitle(string $title, int $volume): string
    {
        $suffix = ' том ' . $volume;
        return rtrim(mb_substr($title, 0, 150 - mb_strlen($suffix))) . $suffix;
    }
}
