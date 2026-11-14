<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\App\Admin;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

class Article extends ResourceObject
{
    #[Purge(uri: 'app://self/articles{?id}')]
    #[Purge(uri: 'app://self/tags{?id}')]
    public function onPost(string $title): static
    {
        return $this;
    }

    #[Refresh(uri: 'app://self/article{?id}')]
    #[Purge(uri: 'app://self/ranking{?id}')]
    public function onPut(string $id): static
    {
        return $this;
    }

    #[Purge(uri: 'app://self/article{?id}')]
    #[Purge(uri: 'app://self/articles{?id}')]
    public function onDelete(string $id): static
    {
        return $this;
    }
}
