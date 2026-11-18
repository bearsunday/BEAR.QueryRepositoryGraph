<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\Page\Layout;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Nav extends ResourceObject
{
    #[Embed(rel: 'categories', src: 'app://self/categories')]
    public function onGet(): static
    {
        return $this;
    }
}
