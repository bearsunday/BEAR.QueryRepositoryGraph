<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Usage\Resource\Page;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[CacheableResponse]
class DonutPage extends ResourceObject
{
    #[Embed(rel: 'read', src: 'app://self/read-child')]
    #[Embed(rel: 'ignored', src: 'app://self/ignored-child')]
    public function onGet(): static
    {
        $this->body['read'] = $this['read']->body;

        return $this;
    }
}
