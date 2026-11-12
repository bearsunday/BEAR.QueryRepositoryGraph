<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class Typo extends ResourceObject
{
    #[DependsOn(uri: 'app://self/artcle{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
