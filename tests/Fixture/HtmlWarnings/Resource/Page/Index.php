<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Index extends ResourceObject
{
    #[Embed(rel: 'feed', src: 'app://self/feed')]
    public function onGet(): static
    {
        return $this;
    }
}
