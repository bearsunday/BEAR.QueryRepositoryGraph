<?php

declare(strict_types=1);

namespace QrGraphDemo\Resource\App;

use BEAR\Resource\ResourceObject;

class Login extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }

    public function onPost(string $name): static
    {
        return $this;
    }
}
