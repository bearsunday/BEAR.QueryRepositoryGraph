<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use QrGraphDemo\Attribute\IndexPage;

#[Cacheable(expiry: 'short')]
#[IndexPage]
class Index extends ResourceObject
{
    #[Embed(rel: 'articles', src: 'app://self/articles')]
    #[Embed(rel: 'ranking', src: 'app://self/ranking')]
    public function onGet(): static
    {
        return $this;
    }
}
