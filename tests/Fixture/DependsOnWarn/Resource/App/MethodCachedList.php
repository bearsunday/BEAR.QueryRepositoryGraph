<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class MethodCachedList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/method-cached{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
