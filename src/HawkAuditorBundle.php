<?php
declare(strict_types=1);
/**
 * This file is part of OwlCorp/HawkAuditor released under GPLv2.
 *
 * Copyright (c) Gregory Zdanowski-House
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace OwlCorp\HawkAuditor;

use OwlCorp\HawkAuditor\DependencyInjection\Compiler\ConfigureFilterProviderPass;
use OwlCorp\HawkAuditor\DependencyInjection\Compiler\DoctrineEvtSubscribersPass;
use OwlCorp\HawkAuditor\DependencyInjection\Compiler\RegisterProducersPass;
use OwlCorp\HawkAuditor\DependencyInjection\Compiler\RegisterSinksPass;
use OwlCorp\HawkAuditor\DependencyInjection\InjectionHelper;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class HawkAuditorBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $injectionHelper = new InjectionHelper();
        $container->addCompilerPass(new RegisterProducersPass($injectionHelper));
        $container->addCompilerPass(new DoctrineEvtSubscribersPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        $container->addCompilerPass(new ConfigureFilterProviderPass());
        $container->addCompilerPass(new RegisterSinksPass());
    }

    public static function getBugReportStatement(): string
    {
        return 'Tickets with issues can be reported at https://github.com/owlcorp/hawk/issues';
    }
}
