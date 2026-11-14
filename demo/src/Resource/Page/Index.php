<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable(expiry: 'short')]
class Index extends ResourceObject
{
    #[Embed(rel: 'articles', src: 'app://self/articles')]
    #[Embed(rel: 'ranking', src: 'app://self/ranking')]
    #[Embed(rel: 'tags', src: 'app://self/tags')]
    #[Embed(rel: 'categories', src: 'app://self/categories')]
    public function onGet(): static
    {
        return $this;
    }
}
