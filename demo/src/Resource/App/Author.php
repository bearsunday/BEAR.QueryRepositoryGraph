<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Author extends ResourceObject
{
    public function onGet(string $id): static
    {
        return $this;
    }
}
