<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\App;

use BEAR\Resource\ResourceObject;

class Ranking extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
