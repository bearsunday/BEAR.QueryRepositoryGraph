<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\Page;

use BEAR\QueryRepositoryGraph\Fixture\Layout\Attribute\TopPage;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
#[TopPage]
class Index extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
