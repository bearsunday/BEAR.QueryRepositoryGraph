<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Attribute;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class TopPage extends AbstractResourceLayout
{
    public function embed(): array
    {
        return [
            'header' => 'page://self/esi/header',
            'ranking' => 'page://self/esi/ranking',
        ];
    }
}
