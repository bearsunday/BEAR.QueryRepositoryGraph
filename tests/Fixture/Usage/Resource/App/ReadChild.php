<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Usage\Resource\App;

use BEAR\Resource\ResourceObject;

class ReadChild extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
