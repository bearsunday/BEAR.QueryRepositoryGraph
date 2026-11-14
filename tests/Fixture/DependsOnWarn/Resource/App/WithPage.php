<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class WithPage extends ResourceObject
{
    #[DependsOn(uri: 'app://self/camel-pair{?leftId,rightId,page}')]
    public function onGet(string $leftId, string $rightId, int $page): static
    {
        return $this;
    }
}
