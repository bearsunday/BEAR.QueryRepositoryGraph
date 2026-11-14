<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Article extends ResourceObject
{
    #[Embed(rel: 'author', src: 'app://self/author{?authorId}')]
    public function onGet(string $id, string $authorId = ''): static
    {
        return $this;
    }

    public function onPut(string $id): static
    {
        return $this;
    }
}
