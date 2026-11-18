<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class SiteLayout extends AbstractResourceLayout
{
    public const EMBED = [
        'menu' => 'app://self/menu',
        'ranking' => 'app://self/ranking?limit=5',
    ];
}
