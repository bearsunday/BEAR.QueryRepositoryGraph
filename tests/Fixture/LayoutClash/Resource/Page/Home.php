<?php

namespace BEAR\QueryRepositoryGraph\Fixture\LayoutClash\Resource\Page;

use BEAR\QueryRepositoryGraph\Fixture\LayoutClash\Attribute\Framed;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Framed]
class Home extends ResourceObject
{
    #[Embed(rel: 'header', src: 'page://self/home-header')]
    public function onGet(): static
    {
        return $this;
    }
}
