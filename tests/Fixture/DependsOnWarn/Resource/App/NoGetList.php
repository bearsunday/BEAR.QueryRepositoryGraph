<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class NoGetList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/no-get{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
