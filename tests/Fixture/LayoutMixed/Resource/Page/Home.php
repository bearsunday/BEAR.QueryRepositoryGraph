<?php

namespace BEAR\QueryRepositoryGraph\Fixture\LayoutMixed\Resource\Page;

use BEAR\QueryRepositoryGraph\Fixture\LayoutMixed\Attribute\MixedPage;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
#[MixedPage]
class Home extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
