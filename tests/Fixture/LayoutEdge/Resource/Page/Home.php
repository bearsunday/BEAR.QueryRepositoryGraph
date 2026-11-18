<?php

namespace BEAR\QueryRepositoryGraph\Fixture\LayoutEdge\Resource\Page;

use BEAR\QueryRepositoryGraph\Fixture\LayoutEdge\Attribute\EdgePage;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
#[EdgePage]
class Home extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
