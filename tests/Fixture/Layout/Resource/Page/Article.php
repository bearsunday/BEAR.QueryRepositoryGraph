<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\Page;

use BEAR\QueryRepositoryGraph\Fixture\Layout\Attribute\ContentPage;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[CacheableResponse]
#[ContentPage]
class Article extends ResourceObject
{
    #[Embed(rel: 'article', src: 'app://self/article')]
    public function onGet(): static
    {
        return $this;
    }
}
