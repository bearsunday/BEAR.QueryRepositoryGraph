<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOn\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Article extends ResourceObject
{
    public function onGet(string $id): static
    {
        return $this;
    }

    public function onPut(string $id): static
    {
        return $this;
    }
}
