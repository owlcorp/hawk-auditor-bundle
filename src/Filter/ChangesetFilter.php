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

namespace OwlCorp\HawkAuditor\Filter;

use OwlCorp\HawkAuditor\DTO\Changeset;

/**
 * Allows in-depth filtering of audit events based on any criteria.
 *
 * The heaviest but the most powerful of all filter types. It is called just before audit records are about to be
 * dispatched to the storage. You can still modify most of the data which allows for almost infinite customization.
 * Keep in mind this filter doesn't cache anything, as any operations can be made on onAudit().
 * This type of entity filter is called last, after all other types of filtering was done, to ensure the most complete
 * set of data being available here.
 *
 * Examples where it is appropriate tu us this filter:
 *  - Determine author of the change based on external data (e.g. you're importing a CSV from external system and the
 *    CSV contains)
 *  - Mask passwords changes without ignoring them as fields
 *  - Automatically exclude sensitive fields contents (https://www.php.net/manual/en/class.sensitive-parameter.php),
 *    which is available in <8.2 with a polyfill
 *  - Ignore changes made by some users but not others
 *  - Only persist audit records for some groups of users (e.g. your DLP policy only warrants logging access by
 *    sales people)
 */
interface ChangesetFilter
{
    public function onAudit(Changeset $changeset): bool;
}
