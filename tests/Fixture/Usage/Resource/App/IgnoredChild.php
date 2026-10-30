<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Usage\Resource\App;

use BEAR\Resource\ResourceObject;

class IgnoredChild extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
