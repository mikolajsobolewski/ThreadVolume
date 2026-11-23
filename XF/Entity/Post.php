<?php

namespace XS\ThreadVolume\XF\Entity;

use XF\Mvc\Entity\Structure;
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

        $thread = $this->Thread;
        if ($this->isStateChanged('message_state', 'visible') === 'enter'
            && !$this->getOption('xs_thread_volume_notice')
            && $this->app()->options()->xsThreadVolumeBotUserId
            && $thread && $thread->discussion_open && $thread->isVisible()
            && VolumeRules::reachedLimit($thread->reply_count))
        {
            $this->app()->jobManager()->enqueueUnique(
                'xsThreadVolume-' . $thread->thread_id,
                ContinueThread::class,
                ['threadId' => $thread->thread_id],
                false
            );
        }
    }
}
