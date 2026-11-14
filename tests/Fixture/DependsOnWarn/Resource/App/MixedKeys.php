<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class MixedKeys extends ResourceObject
{
    #[DependsOn(uri: 'app://self/camel-pair{?leftId,right_id}')]
    public function onGet(string $leftId, string $right_id): static
    {
        return $this;
    }
}
