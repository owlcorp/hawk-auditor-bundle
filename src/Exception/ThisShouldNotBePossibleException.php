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

namespace OwlCorp\HawkAuditor\Exception;

use OwlCorp\HawkAuditor\HawkAuditorBundle;

final class ThisShouldNotBePossibleException extends \RuntimeException
{
    public static function reportIssue(string $message): self
    {
        return new self($message . ' ' . HawkAuditorBundle::getBugReportStatement());
    }
}
