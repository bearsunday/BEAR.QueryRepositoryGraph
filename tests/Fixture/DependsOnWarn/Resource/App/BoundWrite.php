<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

class BoundWrite extends ResourceObject
{
    public function onGet(string $id): static
    {
        return $this;
    }

    public function onPut(#[ResourceParam(uri: 'app://self/article#name', param: 'id')] string $id): static
    {
        return $this;
    }
}
