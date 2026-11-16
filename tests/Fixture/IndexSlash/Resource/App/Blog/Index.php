<?php

namespace BEAR\QueryRepositoryGraph\Fixture\IndexSlash\Resource\App\Blog;

use BEAR\Resource\ResourceObject;

class Index extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
