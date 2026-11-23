<?php

namespace XS\ThreadVolume\Service;

use XF\Entity\Thread;
use XF\Service\AbstractService;
use XF\Service\Thread\CreatorService;
use XF\Service\Thread\ReplierService;
use XS\ThreadVolume\VolumeRules;

class Rollover extends AbstractService
{
    public function apply(int $threadId): ?int
    {
        $db = $this->db();
        if ($db->inTransaction())
        {
            throw new \LogicException('Thread Volume needs its own transaction.');
        }

        $db->beginTransaction();
        try
        {
            $result = $this->createContinuation($threadId);
            $db->commit();
            return $result;
        }
        catch (\Throwable $error)
        {
            $db->rollbackAll();
            throw $error;
        }
    }

    private function createContinuation(int $threadId): ?int
    {
        $db = $this->db();
        $row = $db->fetchRow('SELECT * FROM xf_thread WHERE thread_id = ? FOR UPDATE', $threadId);
        if (!$row || !$row['discussion_open'] || $row['discussion_state'] !== 'visible'
            || $row['discussion_type'] === 'redirect' || !VolumeRules::reachedLimit((int) $row['reply_count'])
            || $db->fetchOne('SELECT next_thread_id FROM xf_xs_thread_volume WHERE source_thread_id = ?', $threadId))
        {
            return null;
        }

        $botId = (int) $this->app->options()->xsThreadVolumeBotUserId;
        if (!$botId)
        {
            return null;
        }
        $bot = $this->app->em()->find('XF:User', $botId);
        if (!$bot)
        {
            throw new \RuntimeException("Service user $botId does not exist.");
        }

        $em = $this->app->em();
        $cached = $em->findCached('XF:Thread', $threadId);
        if ($cached)
        {
            $em->detachEntity($cached);
        }
        /** @var Thread $source */
        $source = $em->instantiateEntity('XF:Thread', $row);
        $forum = $source->Forum;
        if (!$forum)
        {
            throw new \RuntimeException("Thread $threadId has no forum.");
        }
        [$sourceTitle, $nextTitle] = VolumeRules::titles($source->title);

        return \XF::asVisitor($bot, function () use ($db, $source, $sourceTitle, $nextTitle, $forum)
        {
            $creator = $this->service(CreatorService::class, $forum);
            // Notices are trusted system content. Preserve the source prefix, and
            // validate the selected thread type explicitly below.
            $creator->setPerformValidations(false);
            $creator->setPrefix($source->prefix_id);
            $creator->setDiscussionState('visible');
            $creator->setSticky($source->sticky);
            $type = $forum->TypeHandler->isThreadTypeAllowed('discussion', $forum) ? 'discussion' : '';
            if (!$creator->setDiscussionTypeAndDataRaw($type))
            {
                throw new \RuntimeException('The forum does not permit the continuation thread type.');
            }
            $creator->setContent(
                $nextTitle,
                'Предыдущее обсуждение в «' . $this->threadLink($source, $sourceTitle) . '».',
                false
            );
            $next = $creator->save();

            $replier = $this->service(ReplierService::class, $source);
            $replier->getPost()->message_state = 'visible';
            $replier->getPost()->setOption('xs_thread_volume_notice', true);
            $replier->setMessage(
                'Топик закрывается. Продолжение обсуждения в «' . $this->threadLink($next, $next->title) . '».',
                false
            );
            $replier->save();

            $source->title = $sourceTitle;
            $source->sticky = false;
            $source->discussion_open = false;
            $source->save();
            $db->insert('xf_xs_thread_volume', [
                'source_thread_id' => $source->thread_id,
                'next_thread_id' => $next->thread_id,
            ]);

            return $next->thread_id;
        });
    }

    private function threadLink(Thread $thread, string $title): string
    {
        $url = $this->app->router('public')->buildLink('canonical:threads', ['thread_id' => $thread->thread_id]);
        $label = str_ireplace('[/plain]', '［/plain］', $title);
        return "[URL=$url][PLAIN]$label" . '[/PLAIN][/URL]';
    }
}
