<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class OriginById extends ResourceObject
{
    #[DependsOn(uri: 'app://self/origin{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
