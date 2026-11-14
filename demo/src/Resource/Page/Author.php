<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable(expiry: 'medium')]
class Author extends ResourceObject
{
    #[Embed(rel: 'author', src: 'app://self/author{?id}')]
    #[Embed(rel: 'articles', src: 'app://self/articles{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
