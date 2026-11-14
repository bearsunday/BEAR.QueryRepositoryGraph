<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class CamelPair extends ResourceObject
{
    public function onGet(string $rightId, string $leftId, int $page = 1): static
    {
        return $this;
    }

    public function onPut(string $rightId, string $leftId): static
    {
        return $this;
    }
}
