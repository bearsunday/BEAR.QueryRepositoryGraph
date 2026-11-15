<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\Page;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

class Archive extends ResourceObject
{
    #[Embed(rel: 'list', src: 'app://self/archive-list')]
    #[Embed(rel: 'stats', src: 'app://self/stats')]
    #[Embed(rel: 'blog', src: 'app://self/blog/')]
    public function onGet(): static
    {
        return $this;
    }
}
