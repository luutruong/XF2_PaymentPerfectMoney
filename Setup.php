<?php

namespace Truonglv\PaymentPerfectMoney;

use XF\AddOn\AbstractSetup;
use XF\Entity\PaymentProvider;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use Truonglv\PaymentPerfectMoney\DevHelper\SetupTrait;

class Setup extends AbstractSetup
{
    use SetupTrait;
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1(): void
    {
        /** @var PaymentProvider $provider */
        $provider = $this->app()->em()->create('XF:PaymentProvider');
        $provider->provider_id = 'tpm_perfect_money';
        $provider->provider_class = 'Truonglv\PaymentPerfectMoney:PerfectMoney';
        $provider->addon_id = 'Truonglv/PaymentPerfectMoney';
        $provider->save();
    }

    public function uninstallStep1(): void
    {
    }
}
