<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class NoWriteList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/no-write{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
