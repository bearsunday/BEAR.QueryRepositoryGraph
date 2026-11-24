<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class PlainPutList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/plain-put{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
