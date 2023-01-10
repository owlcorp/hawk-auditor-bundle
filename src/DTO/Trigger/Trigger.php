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

namespace OwlCorp\HawkAuditor\DTO\Trigger;

use OwlCorp\HawkAuditor\Type\TriggerSource;

/**
 * @phpstan-type TTriggerContext array<string, scalar|array<scalar|null>|null>
 */
interface Trigger
{
    public function getSource(): TriggerSource;

    /**
     * @return TTriggerContext Should not contain "source" field - it will be overwritten
     */
    public function getContext(): array;
}
