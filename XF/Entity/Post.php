<?php

namespace XS\ThreadVolume\XF\Entity;

use XF\Mvc\Entity\Structure;
use XS\ThreadVolume\Config;
use XS\ThreadVolume\Job\ContinueThread;
use XS\ThreadVolume\VolumeRules;

class Post extends XFCP_Post
{
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);
        $structure->options['xs_thread_volume_notice'] = false;
        return $structure;
    }

    protected function _postSave()
    {
        parent::_postSave();

        $config = Config::from($this->app()->config('xsThreadVolume'));
        $thread = $this->Thread;
        if ($this->isStateChanged('message_state', 'visible') === 'enter'
            && !$this->getOption('xs_thread_volume_notice')
            && $config->botUserId
            && $thread && $thread->discussion_open && $thread->isVisible()
            && VolumeRules::reachedLimit($thread->reply_count, $config->postLimit))
        {
            // Keep a separate job per visibility change. A running job may finish
            // below the threshold while another post is reaching it again.
            $this->app()->jobManager()->enqueue(
                ContinueThread::class,
                ['threadId' => $thread->thread_id]
            );
        }
    }
}
