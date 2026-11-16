<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\Page\Esi;

use BEAR\Resource\ResourceObject;

class Search extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
