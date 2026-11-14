<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class DonutRefreshPostList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/donut-refresh-post{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
