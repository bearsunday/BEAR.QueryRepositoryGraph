<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\Page;

use BEAR\QueryRepositoryGraph\Fixture\Layout\SiteLayout;
use BEAR\Resource\ResourceObject;

#[SiteLayout]
class About extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
