<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOn\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Middle extends ResourceObject
{
    #[DependsOn(uri: 'app://self/article{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }

    public function onPut(string $id): static
    {
        return $this;
    }
}
