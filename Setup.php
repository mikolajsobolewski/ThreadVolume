<?php

namespace XS\ThreadVolume;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1(): void
    {
        $this->schemaManager()->createTable('xf_xs_thread_volume', function (Create $table)
        {
            $table->addColumn('source_thread_id', 'int');
            $table->addColumn('next_thread_id', 'int');
            $table->addPrimaryKey('source_thread_id');
        });
    }

    public function uninstallStep1(): void
    {
        $this->schemaManager()->dropTable('xf_xs_thread_volume');
    }
}
