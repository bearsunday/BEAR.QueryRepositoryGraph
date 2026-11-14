<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class PurgedList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/origin{?articleId}')]
    public function onGet(string $articleId): static
    {
        return $this;
    }
}
