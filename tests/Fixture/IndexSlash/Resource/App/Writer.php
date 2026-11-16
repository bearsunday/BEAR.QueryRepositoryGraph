<?php

namespace BEAR\QueryRepositoryGraph\Fixture\IndexSlash\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class Writer extends ResourceObject
{
    #[Purge(uri: 'app://self/blog/')]
    public function onPut(): static
    {
        return $this;
    }

    #[Purge(uri: 'app://self/blog/index')]
    public function onDelete(): static
    {
        return $this;
    }
}
