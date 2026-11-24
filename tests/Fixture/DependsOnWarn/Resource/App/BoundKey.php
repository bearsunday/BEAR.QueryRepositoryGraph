<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

class BoundKey extends ResourceObject
{
    public function onGet(string $id, #[ResourceParam(uri: 'app://self/article#name', param: 'name')] string $name): static
    {
        return $this;
    }

    public function onPut(string $id): static
    {
        return $this;
    }
}
