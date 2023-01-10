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

namespace OwlCorp\HawkAuditor\Filter\Changeset;

use OwlCorp\HawkAuditor\DTO\Changeset;
use OwlCorp\HawkAuditor\Filter\ChangesetFilter;
use OwlCorp\HawkAuditor\Helper\SymfonySecurityHelper;

/**
 * Symfony contains a peculiar issue - the security system may cause an order-of-operations issue if users are read
 * from the database and read audit is enabled. Doctrine will trigger a read event, which will construct the changeset
 * and the changeset will attempt to grab the user from the token. However, the user token isn't there yet as the user
 * is being read from the database, and thus the user will be null in the changeset.
 *
 * This issue only occurs when users are stored in the database and only when read audit is enabled (or the application
 * reads something before the security system is initialized, which in general you shouldn't do). Thus, this filter
 * is meant to fix this peculiarity. By default, this filter is only registered in Symfony bundle release of this
 * library and only when read audit is enabled, otherwise it's useless.
 *
 * FYI: if you're replacing the filtered processor for some reasons and not calling defined filters, you probably need
 * to replicate this filter's functionality (or call it when needed).
 *
 * @see https://dev.to/aelamel/symfony-empty-token-storage-when-injecting-service-in-an-event-listener-4fh7
 */
final class SymfonyUserProvider implements ChangesetFilter
{
    public function __construct(private SymfonySecurityHelper $securityHelper)
    {
    }

    public function onAudit(Changeset $changeset): bool
    {
        if ($changeset->author === null) {
            $this->securityHelper->populateUsersFromToken($changeset);
        }

        return true;
    }
}
