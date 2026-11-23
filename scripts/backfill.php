<?php

use XS\ThreadVolume\Service\Rollover;
use XS\ThreadVolume\VolumeRules;

if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
$mode = $argv[1] ?? '--preview';
$threadId = null;
if (isset($argv[2]) && preg_match('/^--thread-id=([1-9][0-9]{0,9})$/D', $argv[2], $match))
{
    $threadId = (int) $match[1];
}
if (count($argv) > 3 || !in_array($mode, ['--preview', '--apply'], true)
    || (isset($argv[2]) && $threadId === null))
{
    fwrite(STDERR, "Usage: php scripts/backfill.php [--preview|--apply] [--thread-id=ID]\n");
    exit(2);
}

$apply = $mode === '--apply';
$scanned = $created = $skipped = $failed = 0;
try
{
    $root = dirname(__DIR__, 5);
    require $root . '/src/XF.php';
    \XF::start($root);
    $app = \XF::setupApp(\XF\Cli\App::class);
    $app->start();
    $addon = $app->em()->find('XF:AddOn', 'XS/ThreadVolume');
    if (!$addon || !$addon->active || !$app->options()->xsThreadVolumeBotUserId)
    {
        throw new RuntimeException('Install and enable Thread Volume and configure the service user first.');
    }

    $rollover = $app->service(Rollover::class);
    $last = 0;
    do
    {
        $rows = $app->db()->fetchAll("
            SELECT t.thread_id, t.title
            FROM xf_thread AS t
            LEFT JOIN xf_xs_thread_volume AS v ON v.source_thread_id = t.thread_id
            WHERE t.thread_id > ? AND t.thread_id <= ? AND t.discussion_state = 'visible'
                AND t.discussion_open = 1 AND t.discussion_type <> 'redirect'
                AND t.reply_count >= ? AND v.source_thread_id IS NULL
            ORDER BY t.thread_id
            LIMIT 100
        ", [$threadId === null ? $last : max($last, $threadId - 1), $threadId ?? PHP_INT_MAX, VolumeRules::MIN_REPLIES]);

        foreach ($rows as $row)
        {
            $last = (int) $row['thread_id'];
            $scanned++;
            [$previous, $next] = VolumeRules::titles($row['title']);
            if (!$apply)
            {
                printf("#%d: «%s» → «%s»\n", $last, $previous, $next);
                continue;
            }

            try
            {
                $nextId = $rollover->apply($last);
                if ($nextId)
                {
                    printf("#%d → #%d «%s»\n", $last, $nextId, $next);
                    $created++;
                }
                else
                {
                    printf("#%d skipped: already continued or no longer eligible\n", $last);
                    $skipped++;
                }
            }
            catch (Throwable $error)
            {
                fwrite(STDERR, "#$last failed: {$error->getMessage()}\n");
                $failed++;
            }

            // Keep entity caches bounded even for very large installations.
            $app->em()->clearEntityCache();
        }
    }
    while ($rows);
}
catch (Throwable $error)
{
    fwrite(STDERR, "Thread Volume backfill did not complete: {$error->getMessage()}\n");
    exit(2);
}

printf("%s: %d candidates, %d created, %d skipped, %d failed\n",
    $apply ? 'Apply' : 'Preview', $scanned, $created, $skipped, $failed);
exit($failed ? 2 : 0);
