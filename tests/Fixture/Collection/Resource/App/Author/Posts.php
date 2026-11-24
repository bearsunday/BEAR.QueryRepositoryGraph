<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Collection\Resource\App\Author;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Posts extends ResourceObject
{
    public function onGet(string $author_id, int $page = 1): static
    {
        return $this;
    }
}
