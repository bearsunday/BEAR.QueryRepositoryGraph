<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class Digest extends ResourceObject
{
    #[DependsOn(uri: 'page://self/index{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
