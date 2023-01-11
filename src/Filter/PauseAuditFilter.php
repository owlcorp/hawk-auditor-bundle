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
use OwlCorp\HawkAuditor\Exception\RuntimeException;
use OwlCorp\HawkAuditor\Type\OperationType;
use Psr\Log\LoggerInterface;

final class PauseAuditFilter implements EntityTypeFilter, ChangesetFilter
{
    private bool $auditEvents = true;

    /**
     * @var array{file: string, line: int, function: string, class?: string, type?: string, reason: ?string}
     */
    private array $pauseTrace;

    private bool $enableLogging;
    private int $captures = 0;

    /** @param array<class-string, true> $typesIndex */
    private function __construct(private ?LoggerInterface $log = null)
    {
        $this->enableLogging = $this->log !== null;
    }

    public function pauseAudit(string $reason = null): static
    {
        $this->auditEvents = false;
        $this->pauseTrace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2) + ['reason' => $reason];

        return $this;
    }

    public function resumeAudit(): static
    {
        $this->auditEvents = true;
        unset($this->pauseTrace);
        $this->captures = 0;

        return $this;
    }

    public function logPauses(bool $shouldLogPauses = true): static
    {
        if ($shouldLogPauses) {
            throw new RuntimeException(
                \sprintf(
                    'Cannot enable logging pauses - the "%s" was configured without a logger instance',
                    self::class
                )
            );
        }

        $this->enableLogging = $shouldLogPauses;
    }

    public function getCapturesCount(): int
    {
        return $this->captures;
    }

    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter -- interface declaration conformity
    public function isTypeAuditable(OperationType $opType, string $entityClass): ?bool
    {
        ++$this->captures;

        return $this->auditEvents ? null : false; //we let other filters make a final decision if audits not paused
    }

    public function onAudit(Changeset $changeset): bool
    {
        if ($this->auditEvents) {
            return true;
        }

        if ($this->enableLogging && $this->captures > 0) {
            $source = isset($this->pauseTrace['class'])
                ? $this->pauseTrace['class'] . $this->pauseTrace['type'] . $this->pauseTrace['function']
                : $this->pauseTrace['function'];
            $reason = isset($this->pauseTrace['reason'])
                ? \sprintf('reason: "%s"', $this->pauseTrace['reason']) : 'no reason specified';

            $this->log->notice(
                \sprintf(
                    'Audit logging was paused by "%s" (%s). There were %d events missed since pause.',
                    $source,
                    $reason,
                    $this->captures
                )
            );
        }

        $this->captures = 0;
        return false;
    }
}
