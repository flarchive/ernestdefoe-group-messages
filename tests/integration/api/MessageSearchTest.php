<?php

namespace Ernestdefoe\GroupMessages\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Messages page's search box finds messages by what they say, across every
 * conversation or within one. A group's messages turn up for its members only.
 *
 * MySQL indexes FULLTEXT only on commit, so these rows go in outside the test's
 * transaction, as flarum/messages' own search tests do, and are removed after.
 */
class MessageSearchTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-messages', 'ernestdefoe-group-messages');

        $this->database()->rollBack();

        $now = Carbon::now();
        $user = fn (int $id, string $name) => ['id' => $id, 'username' => $name, 'email' => "$name@machine.local", 'is_email_confirmed' => 1];
        $message = fn (int $id, int $dialog, int $number, string $text) => ['id' => $id, 'dialog_id' => $dialog, 'user_id' => 2, 'number' => $number, 'content' => "<t><p>$text</p></t>", 'created_at' => $now];

        $this->database()->table('users')->insert($this->rowsThroughFactory(User::class, [
            $user(2, 'owner'), $user(3, 'moderator'), $user(4, 'member'), $user(5, 'outsider'),
        ]));

        // 301: a group of 2, 3 and 4. 302: a direct conversation between 2 and 5.
        $this->database()->table('dialogs')->insert([
            ['id' => 301, 'type' => 'group', 'created_at' => $now],
            ['id' => 302, 'type' => 'direct', 'created_at' => $now],
        ]);
        $this->database()->table('group_dialogs')->insert(['dialog_id' => 301, 'title' => 'The squad', 'owner_id' => 2, 'created_at' => $now, 'updated_at' => $now]);
        $this->database()->table('dialog_messages')->insert([
            $message(311, 301, 1, 'Pizza tonight at eight'),
            $message(312, 301, 2, 'Bring the pizza menus'),
            $message(321, 302, 1, 'Pizza later maybe'),
        ]);
        $this->database()->table('dialog_user')->insert(array_map(
            fn ($pair) => ['dialog_id' => $pair[0], 'user_id' => $pair[1], 'joined_at' => $now],
            [[301, 2], [301, 3], [301, 4], [302, 2], [302, 5]]
        ));

        $this->database()->beginTransaction();

        $this->populateDatabase();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->database()->table('dialog_user')->whereIn('dialog_id', [301, 302])->delete();
        $this->database()->table('dialog_messages')->whereIn('dialog_id', [301, 302])->delete();
        $this->database()->table('group_dialogs')->where('dialog_id', 301)->delete();
        $this->database()->table('dialogs')->whereIn('id', [301, 302])->delete();
        $this->database()->table('users')->whereIn('id', [2, 3, 4, 5])->delete();
    }

    /** @return int[] */
    private function found(int $actor, array $filter): array
    {
        $response = $this->send($this->request('GET', '/api/dialog-messages', ['authenticatedAs' => $actor])
            ->withQueryParams(['filter' => $filter, 'sort' => '-number']));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode(), json_encode($body));

        return array_map(fn ($m) => (int) $m['id'], $body['data']);
    }

    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    #[Test]
    public function every_conversation_is_searched_by_default()
    {
        $this->assertSame([311, 312, 321], $this->sorted($this->found(2, ['q' => 'pizza'])));
    }

    #[Test]
    public function one_conversation_is_searched_newest_first()
    {
        $this->assertSame([312, 311], $this->found(2, ['q' => 'pizza', 'dialog' => '301']));
    }

    #[Test]
    public function a_group_member_finds_the_groups_messages_and_not_others()
    {
        $this->assertSame([311, 312], $this->sorted($this->found(4, ['q' => 'pizza'])));
    }

    #[Test]
    public function someone_outside_a_group_cannot_search_it()
    {
        $this->assertSame([], $this->found(5, ['q' => 'pizza', 'dialog' => '301']));
        $this->assertSame([321], $this->found(5, ['q' => 'pizza']));
    }
}
