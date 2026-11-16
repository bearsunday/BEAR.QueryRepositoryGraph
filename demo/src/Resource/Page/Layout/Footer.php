<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\Page\Layout;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable(expiry: 'long')]
class Footer extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
