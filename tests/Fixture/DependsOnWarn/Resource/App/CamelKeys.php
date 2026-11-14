<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class CamelKeys extends ResourceObject
{
    #[DependsOn(uri: 'app://self/camel-pair{?rightId,leftId}')]
    public function onGet(string $rightId, string $leftId): static
    {
        return $this;
    }
}
