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

namespace OwlCorp\HawkAuditor\Producer;

/**
 * Implements the "event producer" EDA layer (https://en.wikipedia.org/wiki/Event-driven_architecture). Events are then
 * stored in unit of work (UnitOfWork class) which calls Processors (Processor namespace).
 *
 * Every AuditProducer instance listed in configuration will receive an appropriate Unit of Work instance. How the UoW
 * is delivered is flexible. Check the full docs, but in short you should either have a constructor with parameters
 * typed as "UnitOfWork $uow" or a method "setUnitOfWork(UnitOfWork $...)".
 *
 * For details see https://symfony.com/doc/current/service_container/injection_types.html
 */
interface AuditProducer
{
}
