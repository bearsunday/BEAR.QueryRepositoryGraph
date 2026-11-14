<?php

namespace BEAR\QueryRepositoryGraph\Fixture\CachedView\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Index extends ResourceObject
{
    #[Embed(rel: 'items', src: 'app://self/items')]
    public function onGet(): static
    {
        return $this;
    }
}
