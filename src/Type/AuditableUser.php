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

/**
 * Helps the audit discover user information without guessing
 */
interface AuditableUser
{
    /**
     * Provide stable, permanent, and unique ID for the audit
     *
     */
    public function getAuditableId(): string;

    /**
     * Provide user-identifiable semi-stable identifier of the user
     *
     * In most systems this will be a login or an email. It serves a similar purpose to Symfony's
     * TokenInterface::getUserIdentifier().
     *
     */
    public function getAuditableName(): ?string;
}
