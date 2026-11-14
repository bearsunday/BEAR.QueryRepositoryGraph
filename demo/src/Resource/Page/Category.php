<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable(expiry: 'medium')]
class Category extends ResourceObject
{
    #[Embed(rel: 'category', src: 'app://self/category{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
