<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\Resource\ResourceObject;

#[DonutCache]
class DonutRefreshPost extends ResourceObject
{
    public function onGet(string $id): static
    {
        return $this;
    }

    #[RefreshCache]
    public function onPost(string $title): static
    {
        return $this;
    }
}
