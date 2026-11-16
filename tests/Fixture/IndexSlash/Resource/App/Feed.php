<?php

namespace BEAR\QueryRepositoryGraph\Fixture\IndexSlash\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Feed extends ResourceObject
{
    #[DependsOn(uri: 'app://self/blog/')]
    public function onGet(): static
    {
        return $this;
    }
}
