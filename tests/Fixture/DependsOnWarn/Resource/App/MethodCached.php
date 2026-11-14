<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

class MethodCached extends ResourceObject
{
    #[CacheableResponse]
    public function onGet(string $id): static
    {
        return $this;
    }

    public function onPost(string $title): static
    {
        return $this;
    }
}
