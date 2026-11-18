<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\Page;

use BEAR\QueryRepositoryGraph\Fixture\Layout\SiteLayout;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
#[SiteLayout]
class Index extends ResourceObject
{
    #[Embed(rel: 'article', src: 'app://self/article?id={id}')]
    public function onGet(string $id): static
    {
        return $this;
    }

    public function onDelete(string $id): static
    {
        return $this;
    }
}
