<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class Alias extends ResourceObject
{
    #[DependsOn(uri: 'app://self/camelPair{?leftId,rightId}')]
    public function onGet(string $leftId, string $rightId): static
    {
        return $this;
    }
}
