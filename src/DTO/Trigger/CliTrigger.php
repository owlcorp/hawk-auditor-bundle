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

class CliTrigger implements Trigger
{
    protected readonly TriggerSource $source;

    public ?string $host;

    /** @var list<string> */
    public array $argv;

    public function __construct()
    {
        $this->source = TriggerSource::CLI;
    }

    public function getSource(): TriggerSource
    {
        return $this->source;
    }

    /**
     * @return array{host: ?string, argv: list<string>}
     */
    public function getContext(): array
    {
        return [
            'host' => $this->host,
            'argv' => $this->argv,
        ];
    }
}
