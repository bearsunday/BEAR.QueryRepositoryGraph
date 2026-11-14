<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class SnakeKeys extends ResourceObject
{
    #[DependsOn(uri: 'app://self/camel-pair{?left_id,right_id}')]
    public function onGet(string $left_id, string $right_id): static
    {
        return $this;
    }
}
