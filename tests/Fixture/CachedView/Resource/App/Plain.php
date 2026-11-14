<?php

namespace BEAR\QueryRepositoryGraph\Fixture\CachedView\Resource\App;

use BEAR\Resource\ResourceObject;

class Plain extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
