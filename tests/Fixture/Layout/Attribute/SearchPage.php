<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Attribute;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class SearchPage extends AbstractResourceLayout
{
    /** @var array<string, string> */
    public const EMBED = ['footer' => ContentPage::EMBED['footer'], 'search' => '/esi/search{?q}'];
}
