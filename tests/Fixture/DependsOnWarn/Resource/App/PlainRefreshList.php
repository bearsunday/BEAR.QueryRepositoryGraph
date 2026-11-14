<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class PlainRefreshList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/plain-refresh{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
