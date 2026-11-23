<?php

namespace XS\ThreadVolume\XF\Service\Thread;

use XF\PrintableException;

class ReplierService extends XFCP_ReplierService
{
    protected function setPostPosition(array $threadInfo)
    {
        // XenForo has locked and reloaded the thread at this point.
        if (!$threadInfo['discussion_open'] && $this->getThread()->discussion_open
            && $this->db()->fetchOne(
                'SELECT next_thread_id FROM xf_xs_thread_volume WHERE source_thread_id = ?',
                $threadInfo['thread_id']
            ))
        {
            $this->db()->rollback();
            throw new PrintableException(\XF::phrase('xs_thread_volume_closed'));
        }

        parent::setPostPosition($threadInfo);
    }
}
