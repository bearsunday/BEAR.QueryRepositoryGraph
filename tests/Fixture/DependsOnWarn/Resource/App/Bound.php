<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

class Bound extends ResourceObject
{
    #[DependsOn(uri: 'app://self/article{?id}')]
    public function onGet(#[ResourceParam(uri: 'app://self/article#id', param: 'id')] string $id = '0'): static
    {
        return $this;
    }
}
