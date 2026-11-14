<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Html\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Front extends ResourceObject
{
    #[Embed(rel: 'banner', src: 'app://self/banner')]
    public function onGet(): static
    {
        return $this;
    }
}
