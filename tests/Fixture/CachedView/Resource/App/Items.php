<?php

namespace BEAR\QueryRepositoryGraph\Fixture\CachedView\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class Items extends ResourceObject
{
    #[DependsOn(uri: 'app://self/item{?id}')]
    public function onGet(string $id = ''): static
    {
        return $this;
    }
}
