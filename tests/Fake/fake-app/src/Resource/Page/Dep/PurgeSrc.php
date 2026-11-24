<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/**
 * Non-Cacheable writer carrying #[Purge]: its own URI and page://self/dep/level-two, which level-one embeds, so the
 * purge cascades to the parent
 */
class PurgeSrc extends ResourceObject
{
    public $body = ['purge-src' => 0];

    public function onGet(string $id)
    {
        $this->body = ['purge-src' => $id];

        return $this;
    }

    #[Purge(uri: 'page://self/dep/purge-src{?id}')]
    #[Purge(uri: 'page://self/dep/level-two')]
    public function onPut(string $id)
    {
        unset($id);

        return $this;
    }
}
