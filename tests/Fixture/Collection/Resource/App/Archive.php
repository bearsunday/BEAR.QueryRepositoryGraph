<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Collection\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Archive extends ResourceObject
{
    public function onGet(int $page = 1): static
    {
        return $this;
    }
}
