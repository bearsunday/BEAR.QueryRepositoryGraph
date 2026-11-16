<?php

namespace BEAR\QueryRepositoryGraph\Fixture\LayoutWithoutEmbed\Resource\Page;

use BEAR\QueryRepositoryGraph\Fixture\LayoutWithoutEmbed\Attribute\Bare;
use BEAR\Resource\ResourceObject;

#[Bare]
class Index extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
