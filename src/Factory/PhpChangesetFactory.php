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

namespace OwlCorp\HawkAuditor\Factory;

use OwlCorp\HawkAuditor\DTO\Changeset;
use OwlCorp\HawkAuditor\DTO\Trigger\CliTrigger;
use OwlCorp\HawkAuditor\DTO\Trigger\HttpTrigger;
use OwlCorp\HawkAuditor\DTO\Trigger\Trigger;
use OwlCorp\HawkAuditor\DTO\User;
use OwlCorp\HawkAuditor\Type\TriggerSource;

/**
 * Creates changeset without any framework-related components
 */
//phpcs:disable SlevomatCodingStandard.Variables.DisallowSuperGlobalVariable -- this factory is used without frameworks
class PhpChangesetFactory implements ChangesetFactory
{
    public function createChangeset(): Changeset
    {
        $changeset = new Changeset();
        $changeset->trigger = $this->createTrigger();

        if ($changeset->trigger->getSource() === TriggerSource::CLI) {
            $this->populateCliAuthor($changeset);
        }

        return $changeset;
    }

    protected function populateCliAuthor(Changeset $changeset): void
    {
        //RHEL disable PHP POSIX even thou it's enabled by default
        if (!\is_callable('posix_geteuid')) {
            return;
        }

        //In POSIX the real user id stays the same and carries over through SUID etc, i.e. this is the user who logged
        // in to the system. Effective UID is the user who is executing the action.
        $eUID = \posix_geteuid();
        $user = new User();
        $user->class = null;
        $user->id = (string)$eUID;
        $user->name = \posix_getpwuid($eUID)['name'] ?? null;
        $changeset->author = $user;

        //Most often people use sudo
        if (isset($_SERVER['SUDO_UID'])) {
            $changeset->impersonator = new User();
            $changeset->impersonator->class = null;
            $changeset->impersonator->id = (string)$_SERVER['SUDO_UID'];
            $changeset->impersonator->name = isset($_SERVER['SUDO_USER'])
                ? (string)$_SERVER['SUDO_USER'] : (\posix_getpwuid($eUID)['name'] ?? null);
            return;
        }

        //Handle SUID case or privileges drop
        $rUID = \posix_getuid();
        if ($eUID === $rUID) {
            return;
        }

        $changeset->impersonator = new User();
        $changeset->impersonator->class = null;
        $changeset->impersonator->id = (string)$rUID;
        $changeset->impersonator->name = \posix_getpwuid($rUID)['name'] ?? null;
    }

    //phpcs:ignore SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh -- it's sparse but well commented
    protected function createTrigger(): Trigger
    {
        //You cannot just check PHP_SAPI, as some people alias cgi binaries to CLI php
        return \PHP_SAPI === 'cli' || //the most straight-forward but not exclusive
            \defined('STDIN') || //only CLI has STDIN
            (\stripos(\PHP_SAPI, '-cgi') !== false && \getenv('TERM') !== false) || //php-cgi aliased to php
            //some HTTP servers stuff php-cgi argv
            (!isset($_SERVER['HTTP_USER_AGENT']) && isset($_SERVER['argv']) && !isset($_SERVER['REMOTE_ADDR']))
            ? $this->createCliTrigger()
            : $this->createHttpTrigger();
    }

    private function createCliTrigger(): CliTrigger
    {
        $trigger = new CliTrigger();
        $hostname = \gethostname();
        $trigger->host = $hostname === false ? null : $hostname;
        $trigger->argv = $_SERVER['argv'] ?? null;

        return $trigger;
    }

    private function createHttpTrigger(): HttpTrigger
    {
        $trigger = new HttpTrigger();
        $trigger->ip = $_SERVER['REMOTE_ADDR'] ?? null;
        //todo: there's no standard way to get request id it seems?

        return $trigger;
    }
}
