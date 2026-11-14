<?php

namespace BEAR\QueryRepositoryGraph\Fixture\CachedView\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class Editor extends ResourceObject
{
    #[Purge(uri: 'app://self/item{?id}')]
    public function onPut(string $id): static
    {
        return $this;
    }
}
