<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOn\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Author extends ResourceObject
{
    public function onGet(string $authorId, int $page = 1): static
    {
        return $this;
    }

    public function onPut(string $authorId): static
    {
        return $this;
    }
}
