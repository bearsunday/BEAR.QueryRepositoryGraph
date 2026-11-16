<?php

namespace BEAR\QueryRepositoryGraph\Fixture\TwoApps\Resource\Page;

use BEAR\Resource\ResourceObject;

class Index extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
