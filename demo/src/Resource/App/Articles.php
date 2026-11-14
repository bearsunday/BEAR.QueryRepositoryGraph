<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Articles extends ResourceObject
{
    #[DependsOn(uri: 'app://self/article{?id}')]
    public function onGet(string $id = '', int $page = 1): static
    {
        return $this;
    }
}
