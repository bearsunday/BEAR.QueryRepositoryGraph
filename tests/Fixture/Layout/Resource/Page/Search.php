<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\Page;

use BEAR\QueryRepositoryGraph\Fixture\Layout\Attribute\SearchPage;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[CacheableResponse]
#[SearchPage]
class Search extends ResourceObject
{
    #[Embed(rel: 'header', src: 'app://self/search-header')]
    public function onGet(): static
    {
        return $this;
    }
}
