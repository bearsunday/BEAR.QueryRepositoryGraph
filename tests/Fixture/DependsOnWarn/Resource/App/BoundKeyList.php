<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class BoundKeyList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/bound-key{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
