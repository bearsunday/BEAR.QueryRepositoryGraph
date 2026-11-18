<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Attribute;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class ContentPage extends AbstractResourceLayout
{
    /** @var array<string, string> */
    public const EMBED = [
        'header' => 'page://self/esi/header',
        'footer' => 'page://self/esi/footer',
    ];
}
