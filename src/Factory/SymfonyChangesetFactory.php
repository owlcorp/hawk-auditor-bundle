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

namespace OwlCorp\HawkAuditor\Factory;

use OwlCorp\HawkAuditor\DTO\Changeset;
use OwlCorp\HawkAuditor\DTO\Trigger\HttpTrigger;
use OwlCorp\HawkAuditor\DTO\Trigger\Trigger;
use OwlCorp\HawkAuditor\Helper\SymfonySecurityHelper;
use OwlCorp\HawkAuditor\Type\TriggerSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Creates changeset without with Symfony awareness
 */
class SymfonyChangesetFactory extends PhpChangesetFactory
{
    public function __construct(
        private ?RequestStack $requestStack,
        private SymfonySecurityHelper $securityHelper
    ) {
    }

    public function createChangeset(): Changeset
    {
        $changeset = new Changeset();
        $changeset->trigger = $this->createTrigger();

        //User info is somewhat tricky. While Symfony firewall and security-core are *usually*, there are some big apps
        // using it in CLI. So we cannot naively assume that CLI==no symfo user. Thus, we try firewall first and then
        // try to make our best from CLI. However, empty user from token may also mean that the token storage is not
        // there yet in some cases (see SymfonyUserProvider).
        $this->securityHelper->populateUsersFromToken($changeset);
        if ($changeset->author === null && $changeset->trigger->getSource() === TriggerSource::CLI) {
            $this->populateCliAuthor($changeset);
        }

        return $changeset;
    }

    /**
     * Unless proven otherwise, this method will fall back to the default PHP implementation, as running
     * "Symfony" doesn't imply that all components are available. Someone can be running only parts of it.
     */
    protected function createTrigger(): Trigger
    {
        if ($this->requestStack === null) {
            return parent::createTrigger();
        }

        $request = $this->requestStack->getCurrentRequest();
        return $request === null ? parent::createTrigger() : $this->createHttpTrigger($request);
    }

    protected function createHttpTrigger(Request $request): HttpTrigger
    {
        $trigger = new HttpTrigger();
        $trigger->ip = $request->getClientIp();
        //todo: there's no standard way to get request id it seems?

        return $trigger;
    }
}
