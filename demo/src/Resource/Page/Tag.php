<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable(expiry: 'medium')]
class Tag extends ResourceObject
{
    #[Embed(rel: 'tag', src: 'app://self/tag{?id}')]
    #[Embed(rel: 'tags', src: 'app://self/tags')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
