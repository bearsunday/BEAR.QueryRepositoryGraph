<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class PostOnlyList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/post-only{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
