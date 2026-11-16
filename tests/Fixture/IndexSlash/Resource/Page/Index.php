<?php

namespace BEAR\QueryRepositoryGraph\Fixture\IndexSlash\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Index extends ResourceObject
{
    #[Embed(rel: 'blog', src: 'app://self/blog/')]
    public function onGet(): static
    {
        return $this;
    }
}
