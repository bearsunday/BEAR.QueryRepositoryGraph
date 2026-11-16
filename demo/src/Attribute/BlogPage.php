<?php

declare(strict_types=1);

namespace QrGraphDemo\Attribute;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class BlogPage extends AbstractResourceLayout
{
    /** @var array<string, string> */
    public const EMBED = [
        'nav' => 'page://self/layout/nav',
        'footer' => 'page://self/layout/footer',
    ];
}
