<?php

namespace XS\ThreadVolume;

final class Config
{
    public const DEFAULT_POST_LIMIT = 40;

    private function __construct(
        public readonly int $botUserId,
        public readonly int $postLimit
    )
    {
    }

    public static function from(mixed $values): self
    {
        $values = is_array($values) ? $values : [];
        $bot = $values['botUserId'] ?? 0;
        $limit = $values['postLimit'] ?? self::DEFAULT_POST_LIMIT;

        if (!is_int($bot) || $bot < 1 || !is_int($limit) || $limit < 2)
        {
            return new self(0, self::DEFAULT_POST_LIMIT);
        }

        return new self($bot, $limit);
    }
}
