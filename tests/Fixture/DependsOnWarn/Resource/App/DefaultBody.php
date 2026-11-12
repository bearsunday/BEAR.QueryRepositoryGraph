<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class DefaultBody extends ResourceObject
{
    /** @var array<string, mixed> */
    public $body = [];

    #[DependsOn(uri: 'app://self/article{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
