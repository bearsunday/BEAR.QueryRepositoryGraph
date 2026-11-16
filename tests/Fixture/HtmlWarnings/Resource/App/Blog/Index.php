<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App\Blog;

use BEAR\Resource\ResourceObject;

class Index extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
