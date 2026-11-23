<?php

namespace XS\ThreadVolume\Service;

use XF\Notifier\Post\ThreadWatch;
use XF\Service\Post\NotifierService;

class OpeningNotifierService extends NotifierService
{
    public static function createForJob(array $extraData)
    {
        $post = \XF::app()->find('XF:Post', $extraData['postId'], ['Thread', 'Thread.Forum']);
        return $post ? \XF::service(self::class, $post, 'reply') : null;
    }

    protected function loadNotifiers()
    {
        return ['threadWatch' => $this->app->notifier(ThreadWatch::class, $this->post, 'reply')];
    }

    public function queueForWatchers(): void
    {
        $watchers = $this->app->finder('XF:ThreadWatch')
            ->where('thread_id', $this->post->thread_id)
            ->where('User.user_state', 'valid')
            ->where('User.is_banned', 0);

        $activeLimit = $this->app->options()->watchAlertActiveOnly;
        if (!empty($activeLimit['enabled']))
        {
            $watchers->where('User.last_activity', '>=', \XF::$time - 86400 * $activeLimit['days']);
        }

        $data = [];
        foreach ($watchers->fetchColumns(['user_id', 'email_subscribe']) as $watch)
        {
            $data[$watch['user_id']] = ['alert' => true, 'email' => (bool) $watch['email_subscribe']];
        }

        // XenForo's default watcher selection skips the first post of a thread.
        $this->setupFromJobData(['notifyData' => ['threadWatch' => $data], 'alerted' => [], 'emailed' => []]);
        $this->enqueueJobIfNeeded();
    }
}
