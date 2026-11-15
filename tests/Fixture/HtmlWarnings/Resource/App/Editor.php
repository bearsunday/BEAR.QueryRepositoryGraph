<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class Editor extends ResourceObject
{
    #[Purge(uri: 'app://self/post{?id}')]
    public function onPut(string $id): static
    {
        return $this;
    }

    #[Purge(uri: 'app://self/counter')]
    public function onDelete(): static
    {
        return $this;
    }
}
