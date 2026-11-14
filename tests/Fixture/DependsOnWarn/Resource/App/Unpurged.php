<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class Unpurged extends ResourceObject
{
    #[DependsOn(uri: 'app://self/plain{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
