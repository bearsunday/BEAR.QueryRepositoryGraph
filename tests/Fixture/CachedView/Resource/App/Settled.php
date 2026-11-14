<?php

namespace BEAR\QueryRepositoryGraph\Fixture\CachedView\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable(expiry: 'long')]
class Settled extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
