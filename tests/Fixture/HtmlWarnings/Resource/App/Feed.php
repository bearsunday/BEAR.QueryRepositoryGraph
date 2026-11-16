<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

class Feed extends ResourceObject
{
    #[Embed(rel: 'ad', src: 'app://ads/banner')]
    #[DependsOn(uri: 'app://self/post{?id}')]
    public function onGet(string $id = ''): static
    {
        return $this;
    }
}
