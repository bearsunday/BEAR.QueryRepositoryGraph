<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class PairList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/pair{?right,left}')]
    public function onGet(string $left, string $right): static
    {
        return $this;
    }
}
