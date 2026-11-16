<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\App;

use BEAR\Resource\ResourceObject;

class SearchHeader extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
