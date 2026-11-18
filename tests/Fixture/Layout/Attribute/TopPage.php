<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Attribute;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class TopPage extends AbstractResourceLayout
{
    /** @var array<string, string> */
    public const EMBED = [
        'header' => 'page://self/esi/header',
        'ranking' => 'page://self/esi/ranking',
    ];
}
