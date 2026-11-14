<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\Resource\ResourceObject;

class Origin extends ResourceObject
{
    public function onGet(string $id): static
    {
        return $this;
    }
}
