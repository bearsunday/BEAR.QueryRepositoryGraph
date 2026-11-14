<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use Ray\WebContextParam\Annotation\QueryParam;

class Bound extends ResourceObject
{
    #[DependsOn(uri: 'app://self/article{?id}')]
    public function onGet(#[QueryParam('id')] string $id = '0'): static
    {
        return $this;
    }
}
