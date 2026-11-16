<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\Page\Esi;

use BEAR\Resource\ResourceObject;

class Footer extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
