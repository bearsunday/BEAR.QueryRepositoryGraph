<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\Page\Layout;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable(expiry: 'short')]
class Pickup extends ResourceObject
{
    #[Embed(rel: 'tags', src: 'app://self/tags')]
    public function onGet(): static
    {
        return $this;
    }
}
