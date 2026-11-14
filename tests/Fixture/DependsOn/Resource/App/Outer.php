<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class Outer extends ResourceObject
{
    #[DependsOn(uri: 'app://self/middle{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
