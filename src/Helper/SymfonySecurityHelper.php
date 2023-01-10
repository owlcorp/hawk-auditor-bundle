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
/**
 * This file is part of OwlCorp/HawkAuditor released under GPLv2.
 *
 * Copyright (c) Gregory Zdanowski-House
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace OwlCorp\HawkAuditor\Helper;

use OwlCorp\HawkAuditor\DTO\Changeset;
use OwlCorp\HawkAuditor\DTO\User;
use OwlCorp\HawkAuditor\Type\AuditableUser;
use OwlCorp\HawkAuditor\Type\UserType;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class SymfonySecurityHelper
{
    public function __construct(private ?TokenStorageInterface $tokenStorage)
    {
    }

    public function populateUsersFromToken(Changeset $changeset): void
    {
        $token = $this->tokenStorage?->getToken();
        if ($token === null) {
            return;
        }

        $changeset->author = $this->tokenToDto($token);
        if ($token instanceof SwitchUserToken) {
            $changeset->impersonator = $this->tokenToDto($token->getOriginalToken());
        }
    }

    private function tokenToDto(TokenInterface $token): User
    {
        $user = new User();

        //Note: $token->getUserIdentifier() was added in Symfony 5.3:
        // https://github.com/symfony/symfony/commit/8afd7a3765ff1258ef46ca70a1102dbe51762644
        $tokenUser = $token->getUser();
        if ($tokenUser !== null) {
            $user->class = $tokenUser::class;
            $user->type = UserType::TOKEN;
            $user->id = $this->getUidFromSecurity($tokenUser);
            $user->name = $tokenUser instanceof AuditableUser
                            ? $tokenUser->getAuditableName() : $tokenUser->getUserIdentifier();

            return $user;
        }

        $user->class = $token::class;
        $user->name = $token->getUserIdentifier();
        return $user;
    }

    private function getUidFromSecurity(UserInterface $user): ?string
    {
        //Try explicitly configured one first
        if ($user instanceof AuditableUser) {
            return $user->getAuditableId();
        }

        //Most common one
        if (isset($user->id)) {
            return $user->id;
        }
        $callable = [$user, 'getId'];
        if (\is_callable($callable)) {
            return (string)$callable();
        }

        //DDD people sometimes do that
        $name = (new \ReflectionObject($user))->getShortName(); //this is faster than any string manipulations
        if (isset($user->$name)) {
            return $user->$name;
        }
        $callable = [$user, 'get' . $name];
        if (\is_callable($callable)) {
            return (string)$callable();
        }

        return null;
    }
}
