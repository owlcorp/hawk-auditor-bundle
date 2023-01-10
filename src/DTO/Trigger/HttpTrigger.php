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

class HttpTrigger implements Trigger
{
    protected TriggerSource $source = TriggerSource::HTTP;

    public ?string $requestId = null;
    public ?string $ip;

    public function getSource(): TriggerSource
    {
        return $this->source;
    }

    /**
     * @return array{reqId: ?string, ip: ?string}
     */
    public function getContext(): array
    {
        return [
            'reqId' => $this->requestId,
            'ip' => $this->ip,
        ];
    }
}
