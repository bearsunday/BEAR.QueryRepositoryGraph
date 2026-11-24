<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Collection\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\Annotation\Link;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Post extends ResourceObject
{
    #[Link(rel: 'collection', href: 'app://self/posts')]
    #[Link(rel: 'collection', href: 'app://self/author/posts?author_id={author_id}')]
    #[Link(rel: 'collection', href: '/posts')]
    #[Link(rel: 'author', href: 'app://self/author{?author_id}')]
    public function onGet(string $id): static
    {
        return $this;
    }

    public function onPut(string $id): static
    {
        return $this;
    }

    public function ONDELETE(string $id): static
    {
        return $this;
    }

    #[Purge(uri: 'app://self/posts')]
    public function onPostProcess(string $id): static
    {
        return $this;
    }
}
