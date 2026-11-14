<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Category extends ResourceObject
{
    #[Embed(rel: 'articles', src: 'app://self/articles{?id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
