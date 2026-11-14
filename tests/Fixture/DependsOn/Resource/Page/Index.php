<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOn\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Index extends ResourceObject
{
    #[Embed(rel: 'entries', src: 'app://self/entries?article_id={id}&author_id=1')]
    public function onGet(string $id): static
    {
        return $this;
    }

    public function onDelete(string $id): static
    {
        return $this;
    }
}
