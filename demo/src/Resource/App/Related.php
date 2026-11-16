<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Related extends ResourceObject
{
    // Wrong on purpose, for the warnings to show: writes purge app://self/article?id=…, never ?articleId=….
    #[DependsOn(uri: 'app://self/article{?articleId}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
