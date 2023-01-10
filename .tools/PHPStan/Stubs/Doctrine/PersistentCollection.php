<?php
declare(strict_types=1);

namespace Doctrine\ORM;

class PersistentCollection
{
    /**
     * @phpstan-return array{mappedBy: mixed|null,
     *                      inversedBy: mixed|null,
     *                      isOwningSide: bool,
     *                      sourceEntity: class-string,
     *                      targetEntity: string,
     *                      fieldName: string,
     *                      fetch: mixed,
     *                      cascade: array<array-key,string>,
     *                      isCascadeRemove: bool,
     *                      isCascadePersist: bool,
     *                      isCascadeRefresh: bool,
     *                      isCascadeMerge: bool,
     *                      isCascadeDetach: bool,
     *                      type: int,
     *                      originalField: string,
     *                      originalClass: class-string,
     *                      orphanRemoval?: bool
     *                     }|null
     */
    public function getMapping(): ?array {}



}
