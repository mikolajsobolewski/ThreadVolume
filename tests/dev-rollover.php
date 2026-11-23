<?php

use XF\Db\DeadlockException;
use XF\Job\Notifier;
use XF\PrintableException;
use XF\Service\Thread\CreatorService;
use XF\Service\Thread\ReplierService;
use XS\ThreadVolume\Job\ContinueThread;
use XS\ThreadVolume\Service\OpeningNotifierService;
use XS\ThreadVolume\Service\Rollover;

if (PHP_SAPI !== 'cli' || getenv('ONANIZM_DRIFT_SITE') !== 'dev')
{
    fwrite(STDERR, "Run this fixture script on local dev only.\n");
    exit(2);
}

$root = dirname(__DIR__, 5);
require $root . '/src/XF.php';
\XF::start($root);
$app = \XF::setupApp(\XF\Cli\App::class);
$app->start();
// The local fixture may use a copy whose administrator has no recent activity.
$app->options()->watchAlertActiveOnly = ['enabled' => false];
$db = $app->db();
$em = $app->em();
$bot = $em->find('XF:User', $app->options()->xsThreadVolumeBotUserId);
$forum = $em->findOne('XF:Forum', ['forum_type_id' => 'discussion', 'require_prefix' => 0, 'min_tags' => 0]);
if (!$bot || !$forum)
{
    fwrite(STDERR, "Test needs a service account and a discussion forum without a required prefix or tags.\n");
    exit(2);
}

$total = 0;
function check(bool $ok, string $message): void
{
    global $total;
    $total++;
    if (!$ok)
    {
        throw new RuntimeException($message);
    }
    printf("PASS %s\n", $message);
}

$marker = 'ONAN-353 dev ' . bin2hex(random_bytes(6));
$sourceIds = [];
$testNodeId = null;
$exit = 0;
try
{
    \XF::asVisitor($bot, function () use ($app, $db, $em, $forum, $bot, $marker, &$sourceIds, &$testNodeId)
    {
        $make = function (string $title) use ($app, $forum, &$sourceIds)
        {
            $creator = $app->service(CreatorService::class, $forum);
            $creator->setDiscussionState('visible');
            $creator->setSticky(true);
            $creator->setContent($title, 'Тестовое обсуждение ONAN-353', false);
            $thread = $creator->save();
            $sourceIds[] = $thread->thread_id;
            return $thread;
        };
        $reply = function ($thread, string $state = 'visible', bool $notice = false) use ($app)
        {
            $replier = $app->service(ReplierService::class, $thread);
            $replier->getPost()->message_state = $state;
            $replier->getPost()->setOption('xs_thread_volume_notice', $notice);
            $replier->setMessage('Тестовый ответ ONAN-353', false);
            return $replier;
        };
        $jobs = fn ($id) => $db->fetchAllColumn(
            'SELECT job_id FROM xf_job WHERE execute_class = ? AND execute_data = ? ORDER BY job_id',
            [ContinueThread::class, serialize(['threadId' => $id])]
        );
        $countJob = fn ($id) => count($jobs($id));
        $nextId = fn ($id) => (int) $db->fetchOne(
            'SELECT next_thread_id FROM xf_xs_thread_volume WHERE source_thread_id = ?', $id
        );
        $first = $make($marker);
        $subscriber = $em->findOne('XF:User', ['is_admin' => 1, 'user_state' => 'valid']);
        if (!$subscriber || $subscriber->user_id === $bot->user_id)
        {
            throw new RuntimeException('Test needs an administrator distinct from the service user.');
        }
        $db->insert('xf_thread_watch', [
            'thread_id' => $first->thread_id, 'user_id' => $subscriber->user_id, 'email_subscribe' => 0
        ]);
        for ($i = 0; $i < 38; $i++)
        {
            $reply($first)->save();
        }
        check(!$countJob($first->thread_id) && !$nextId($first->thread_id), '39 visible posts do not schedule rollover');
        $pending = $reply($first, 'moderated')->save();
        check(!$countJob($first->thread_id), 'moderated post does not count before approval');
        $pending->message_state = 'visible';
        $pending->save();
        check((int) $countJob($first->thread_id) === 1, 'approval of the 40th visible post schedules one job');
        $staleReplier = $reply($first);
        $app->jobManager()->runById($jobs($first->thread_id)[0], 10);
        $secondId = $nextId($first->thread_id);
        [$closed, $second] = [$em->find('XF:Thread', $first->thread_id), $em->find('XF:Thread', $secondId)];

        check($second && $second->node_id === $closed->node_id && $second->discussion_open, 'job creates an open continuation in the same forum');
        check(!$closed->discussion_open && $closed->title === "$marker том 1" && $second->title === "$marker том 2",
            'job closes and renames the source and names volume 2');
        check(!(int) $db->fetchOne('SELECT COUNT(*) FROM xf_thread_watch WHERE thread_id = ?', $closed->thread_id)
            && ($db->fetchRow('SELECT email_subscribe FROM xf_thread_watch WHERE thread_id = ? AND user_id = ?',
                [$second->thread_id, $subscriber->user_id])['email_subscribe'] ?? null) === 0, 'subscription and alert-only preference move');
        $openingJob = null;
        foreach ($db->fetchAll('SELECT job_id, execute_data FROM xf_job WHERE execute_class = ?', Notifier::class) as $job)
        {
            $data = unserialize($job['execute_data'], ['allowed_classes' => false]);
            if ($data['service'] === OpeningNotifierService::class && $data['extra']['postId'] === $second->first_post_id)
            {
                $openingJob = $job['job_id'];
                break;
            }
        }
        check((bool) $openingJob, 'opening notification is queued for the subscriber');
        $app->jobManager()->runById($openingJob, 10);
        $alerts = $db->fetchAllColumn(
            "SELECT content_id FROM xf_user_alert WHERE alerted_user_id = ? AND content_type = 'post' AND content_id IN (?, ?)",
            [$subscriber->user_id, $second->first_post_id, $closed->last_post_id]
        );
        check(array_map('intval', $alerts) === [$second->first_post_id], 'subscriber receives exactly one alert for the opening post');
        check(!$closed->sticky && $second->sticky, 'pin follows the active volume');
        check($closed->reply_count + 1 === 41 && $second->reply_count + 1 === 1, 'closing and opening notices are saved once');
        check($closed->LastPost->user_id === $bot->user_id && $second->FirstPost->user_id === $bot->user_id,
            'both notices are published as the configured user');

        foreach ([[$closed->LastPost, $second], [$second->FirstPost, $closed]] as [$post, $target])
        {
            $html = $app->bbCode()->render($post->message, 'html', 'post', $post);
            check((bool) preg_match('/<a\s[^>]*href="[^"]*threads\/(?:[^"\/]*\.)?' . $target->thread_id . '\/[^"]*"/', $html)
                && str_contains($html, $target->title), 'rendered notice links to volume #' . $target->thread_id);
        }

        try
        {
            $staleReplier->save();
            throw new RuntimeException('A stale reply reached a closed volume.');
        }
        catch (PrintableException $error)
        {
            check(true, 'reply prepared before rollover is rejected after the source closes');
        }
        $rollover = $app->service(Rollover::class);
        check($rollover->apply($closed->thread_id) === null, 'retry does not create another continuation');

        $sourceIds[] = $second->thread_id;
        for ($i = 0; $i < 39; $i++)
        {
            $reply($second)->save();
        }
        check((int) $countJob($second->thread_id) === 1, 'opening notice counts toward the next rollover');
        $app->jobManager()->runById($jobs($secondId)[0], 10);
        $third = $em->find('XF:Thread', $nextId($secondId));
        check($third && $third->title === "$marker том 3", 'volume 2 continues into volume 3');

        $legacy = $make("$marker (т. 3)");
        for ($i = 0; $i < 41; $i++)
        {
            $reply($legacy, 'visible', true)->save();
        }
        check(!$countJob($legacy->thread_id), 'legacy fixture starts over threshold without a queued job');
        $failClosing = true;
        $app->extension()->addListener('entity_pre_save', function ($thread) use ($legacy, &$failClosing)
        {
            if ($failClosing && $thread->thread_id === $legacy->thread_id && !$thread->discussion_open)
            {
                throw new RuntimeException('Injected close failure');
            }
        }, 'XF\Entity\Thread');
        try
        {
            $rollover->apply($legacy->thread_id);
            throw new RuntimeException('Expected closing to fail');
        }
        catch (RuntimeException $error)
        {
            check($error->getMessage() === 'Injected close failure', 'fault injected after writing both notices');
        }
        finally
        {
            $failClosing = false;
        }
        $row = $db->fetchRow('SELECT title, discussion_open, sticky, reply_count FROM xf_thread WHERE thread_id = ?',
            $legacy->thread_id);
        check($row['title'] === "$marker (т. 3)" && $row['discussion_open'] && $row['sticky']
            && (int) $row['reply_count'] === 41 && !$nextId($legacy->thread_id)
            && (int) $db->fetchOne('SELECT COUNT(*) FROM xf_thread WHERE title = ?', "$marker том 4") === 0,
            'failed rollover rolls back the new thread, notices, rename and pin transfer');
        $failOnce = true;
        $app->extension()->addListener('entity_pre_save', function ($thread) use ($legacy, &$failOnce)
        {
            if ($failOnce && $thread->thread_id === $legacy->thread_id && !$thread->discussion_open)
            {
                $failOnce = false;
                throw new DeadlockException('Injected transient database error');
            }
        }, 'XF\Entity\Thread');
        $em->clearEntityCache();
        $reply($em->find('XF:Thread', $legacy->thread_id))->save();
        $jobId = $jobs($legacy->thread_id)[0];
        $app->jobManager()->runById($jobId, 10);
        $retry = $db->fetchRow('SELECT attempts, trigger_date FROM xf_job WHERE job_id = ?', $jobId);
        check((int) $retry['attempts'] === 1 && (int) $retry['trigger_date'] > time() && !$nextId($legacy->thread_id),
            'deadlock rolls back and schedules a retry');
        $db->update('xf_job', ['trigger_date' => time()], 'job_id = ?', $jobId);
        $app->jobManager()->runById($jobId, 10);
        $newId = $nextId($legacy->thread_id);
        $em->clearEntityCache();
        check($newId && $em->find('XF:Thread', $newId)->title === "$marker том 4"
            && $em->find('XF:Thread', $legacy->thread_id)->title === "$marker (т. 3)",
            'retry after a fresh reply opens the next legacy volume');

        $collision = $make("$marker conflict");
        $make("$marker conflict том 2");
        for ($i = 0; $i < 39; $i++)
        {
            $reply($collision)->save();
        }
        $app->jobManager()->runById($jobs($collision->thread_id)[0], 10);
        check(!$nextId($collision->thread_id) && $em->find('XF:Thread', $collision->thread_id)->discussion_open,
            'existing continuation title leaves source open');

        $again = $make("$marker threshold");
        for ($i = 0; $i < 38; $i++)
        {
            $reply($again)->save();
        }
        $trigger = $reply($again)->save();
        $trigger->message_state = 'deleted';
        $trigger->save();
        $oldJob = $jobs($again->thread_id)[0];
        check($rollover->apply($again->thread_id) === null, 'running job observes that the count fell below the limit');
        $reply($again)->save();
        $newJobs = $jobs($again->thread_id);
        check(count($newJobs) === 2 && $newJobs[0] === $oldJob, 'return to the threshold has its own pending job');
        $db->delete('xf_job', 'job_id = ?', $oldJob);
        $app->jobManager()->runById($newJobs[1], 10);
        check((bool) $nextId($again->thread_id), 'dequeue of the first attempt cannot remove the new trigger');

        $node = $em->create('XF:Node');
        $node->title = "$marker questions";
        $node->node_type_id = 'Forum';
        $questionForum = $node->getDataRelationOrDefault();
        $questionForum->forum_type_id = 'question';
        $node->save();
        $testNodeId = $node->node_id;
        $questionCreator = $app->service(CreatorService::class, $questionForum);
        $questionCreator->setDiscussionState('visible');
        $questionCreator->setDiscussionTypeAndDataRaw('question');
        $questionCreator->setContent("$marker question", 'Проверка раздела вопросов ONAN-353', false);
        $question = $questionCreator->save();
        $sourceIds[] = $question->thread_id;
        for ($i = 0; $i < 39; $i++)
        {
            $reply($question, 'visible', true)->save();
        }
        $questionNext = $em->find('XF:Thread', $rollover->apply($question->thread_id));
        check($questionNext && $questionNext->node_id === $testNodeId && $questionNext->discussion_type === 'question',
            'continuation uses the default type where discussion is unavailable');

    });
}
catch (Throwable $error)
{
    $db->rollbackAll();
    fwrite(STDERR, "FAIL {$error->getMessage()}\n{$error->getTraceAsString()}\n");
    $exit = 1;
}
finally
{
    $ids = $db->fetchAllColumn('SELECT thread_id FROM xf_thread WHERE title LIKE ?', "$marker%");
    foreach (array_unique(array_merge($ids, $sourceIds)) as $id)
    {
        $db->delete('xf_xs_thread_volume', 'source_thread_id = ?', $id);
        $db->delete('xf_job', 'execute_class = ? AND execute_data = ?',
            [ContinueThread::class, serialize(['threadId' => (int) $id])]);
        $em->clearEntityCache();
        $thread = $em->find('XF:Thread', $id);
        if ($thread)
        {
            $thread->delete();
        }
    }
    if ($testNodeId)
    {
        $em->clearEntityCache();
        $em->find('XF:Node', $testNodeId)->delete();
    }
}
printf("%d integration checks\n", $total);
exit($exit);
