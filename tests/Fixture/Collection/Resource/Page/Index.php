<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Collection\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Index extends ResourceObject
{
    #[Embed(rel: 'posts', src: 'app://self/posts{?page}')]
    public function onGet(int $page = 1): static
    {
        return $this;
    }
}
