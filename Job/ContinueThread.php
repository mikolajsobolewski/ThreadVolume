<?php

namespace XS\ThreadVolume\Job;

use XF\Db\DeadlockException;
use XF\Db\Exception as DbException;
use XF\Job\AbstractJob;
use XF\Job\JobResult;
use XS\ThreadVolume\Service\Rollover;

class ContinueThread extends AbstractJob
{
    public function run($maxRunTime)
    {
        try
        {
            $this->app->service(Rollover::class)->apply((int) $this->data['threadId']);
        }
        catch (DbException $error)
        {
            $attempts = $this->getPreviousAttempts();
            if ($attempts >= 3 || (!$error instanceof DeadlockException && (int) $error->getCode() !== 1205))
            {
                throw $error;
            }

            return JobResult::newReattempt($this->jobId, time() + 15 * (2 ** $attempts), $this->data);
        }
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
