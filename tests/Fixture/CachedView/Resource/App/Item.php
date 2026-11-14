<?php

namespace BEAR\QueryRepositoryGraph\Fixture\CachedView\Resource\App;

use BEAR\Resource\ResourceObject;

class Item extends ResourceObject
{
    public function onGet(string $id): static
    {
        return $this;
    }
}
