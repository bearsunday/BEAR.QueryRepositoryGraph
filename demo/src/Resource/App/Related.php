<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Related extends ResourceObject
{
    #[DependsOn(uri: 'app://self/article?id={articleId}')]
    public function onGet(string $articleId): static
    {
        return $this;
    }
}
