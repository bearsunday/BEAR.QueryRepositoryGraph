<?php

namespace BEAR\QueryRepositoryGraph\Fixture\TwoApps\Other\Resource\App;

use BEAR\Resource\ResourceObject;

class Item extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
