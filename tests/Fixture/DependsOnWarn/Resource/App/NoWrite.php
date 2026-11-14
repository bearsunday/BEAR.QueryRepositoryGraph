<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class NoWrite extends ResourceObject
{
    public function onGet(string $id): static
    {
        return $this;
    }
}
