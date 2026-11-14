<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\Resource\ResourceObject;

class Plain extends ResourceObject
{
    public function onGet(string $id): static
    {
        return $this;
    }

    public function onPut(string $id): static
    {
        return $this;
    }
}
