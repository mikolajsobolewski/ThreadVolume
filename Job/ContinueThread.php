<?php

namespace XS\ThreadVolume\Job;

use XF\Job\AbstractJob;
use XS\ThreadVolume\Service\Rollover;

class ContinueThread extends AbstractJob
{
    public function run($maxRunTime)
    {
        $this->app->service(Rollover::class)->apply((int) $this->data['threadId']);
        return $this->complete();
    }

    public function getStatusMessage()
    {
        return 'Creating the next thread volume';
    }

    public function canCancel()
    {
        return false;
    }

    public function canTriggerByChoice()
    {
        return false;
    }
}
