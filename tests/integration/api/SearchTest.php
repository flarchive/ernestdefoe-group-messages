<?php

namespace Ernestdefoe\GroupMessages\Tests\integration\api;

use Ernestdefoe\GroupMessages\Tests\integration\GroupMessagesTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Messages page's search box finds conversations by who they are with, or
 * by a group's name — only ever the searcher's own.
 */
class SearchTest extends GroupMessagesTestCase
{
    /** @return int[] */
    private function conversations(int $actor, string $q): array
    {
        [$status, $body] = $this->call('GET', '/api/dialogs', $actor, null, ['filter' => ['q' => $q]]);
        $this->assertSame(200, $status, json_encode($body));

        $ids = array_map(fn ($d) => (int) $d['id'], $body['data']);
        sort($ids);

        return $ids;
    }

    #[Test]
    public function a_conversation_is_found_by_who_it_is_with()
    {
        $this->assertSame([2], $this->conversations(2, 'outsi'));
        $this->assertSame([1], $this->conversations(2, 'MEMBER'));
    }

    #[Test]
    public function a_group_is_found_by_its_name()
    {
        $this->assertSame([1], $this->conversations(4, 'squad'));
    }

    #[Test]
    public function your_own_name_finds_nothing()
    {
        $this->assertSame([], $this->conversations(5, 'outsider'));
    }

    #[Test]
    public function nobody_finds_a_conversation_they_are_not_in()
    {
        $this->assertSame([], $this->conversations(5, 'squad'));
        $this->assertSame([], $this->conversations(5, 'member'));
    }

    #[Test]
    public function like_wildcards_are_taken_literally()
    {
        $this->assertSame([], $this->conversations(2, '%'));
        $this->assertSame([], $this->conversations(2, '_'));
    }

    #[Test]
    public function a_name_with_an_underscore_is_found_by_it()
    {
        $this->app();
        $this->database()->table('users')->where('id', 5)->update(['username' => 'out_sider']);

        $this->assertSame([2], $this->conversations(2, 'out_'));
        $this->assertSame([], $this->conversations(2, 'outs'));
    }
}
