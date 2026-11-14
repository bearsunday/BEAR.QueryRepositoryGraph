<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;

class Archive extends Entries
{
    #[DependsOn(uri: 'app://self/author?author_id={author_id}')]
    public function onGet(string $articleId, string $author_id): static
    {
        return $this;
    }
}
