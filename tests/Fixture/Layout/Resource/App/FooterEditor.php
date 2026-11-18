<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class FooterEditor extends ResourceObject
{
    #[Purge(uri: 'page://self/esi/footer')]
    public function onPut(): static
    {
        return $this;
    }
}
