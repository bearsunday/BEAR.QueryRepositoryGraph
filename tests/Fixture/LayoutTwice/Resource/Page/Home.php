<?php

namespace BEAR\QueryRepositoryGraph\Fixture\LayoutTwice\Resource\Page;

use BEAR\QueryRepositoryGraph\Fixture\LayoutTwice\Attribute\First;
use BEAR\QueryRepositoryGraph\Fixture\LayoutTwice\Attribute\Second;
use BEAR\Resource\ResourceObject;

#[First]
#[Second]
class Home extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
