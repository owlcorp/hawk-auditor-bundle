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

namespace OwlCorp\HawkAuditor\Type;

enum OperationType: string
{
    /** Entity was persisted as new */
    case CREATE = 'insert';

    /** Entity was read */
    case READ = 'read';

    /** Entity data was updated */
    case UPDATE = 'update';

    /** Entity was removed */
    case DELETE = 'delete';

    /**
     * A manual snapshot of the entity state was requested.
     * This can be used for e.g. reverting previous complete state of the entity
     * @experimental this is not yet implemented!
     */
    case SNAPSHOT = 'snapshot';
}
