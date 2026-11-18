<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\Page\Esi;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Header extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
