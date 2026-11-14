<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class DefaultCode extends ResourceObject
{
    /** @var int */
    public $code = 201;

    #[DependsOn(uri: 'app://self/article{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
