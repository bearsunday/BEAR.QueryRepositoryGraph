<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use QrGraphDemo\Attribute\BlogPage;

#[Cacheable(expiry: 'medium')]
#[BlogPage]
class Article extends ResourceObject
{
    #[Embed(rel: 'article', src: 'app://self/article{?id}')]
    #[Embed(rel: 'comments', src: 'app://self/comments{?id}')]
    #[Embed(rel: 'related', src: 'app://self/related{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
