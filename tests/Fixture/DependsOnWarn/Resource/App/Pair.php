<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Pair extends ResourceObject
{
    public function onGet(string $left, string $right): static
    {
        return $this;
    }

    public function onPut(string $left, string $right): static
    {
        return $this;
    }
}
