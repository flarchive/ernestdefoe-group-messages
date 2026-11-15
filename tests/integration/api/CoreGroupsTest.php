<?php

namespace Ernestdefoe\GroupMessages\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\GroupMessages\Tests\integration\GroupMessagesTestCase;
use Flarum\Messages\Dialog;
use PHPUnit\Framework\Attributes\Test;

/**
 * Flarum 2.1 starts groups of its own: type 'group', with no group_dialogs row.
 * Group Messages must leave those to core (no owner, roles or management) and
 * keep running its own. Dialog 3 is such a group of 2, 3 and 4.
 */
class CoreGroupsTest extends GroupMessagesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase([
            'dialogs' => [['id' => 3, 'type' => 'group', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]],
            'dialog_user' => array_map(fn (int $user) => ['dialog_id' => 3, 'user_id' => $user, 'joined_at' => Carbon::now(), 'last_read_message_id' => 0], [2, 3, 4]),
        ]);
    }

    private function attributes(int $dialog, int $actor): array
    {
        [$status, $body] = $this->call('GET', "/api/dialogs/$dialog", $actor);
        $this->assertSame(200, $status, json_encode($body));

        return $body['data']['attributes'];
    }

    #[Test]
    public function a_core_group_has_no_owner_or_roles()
    {
        $attributes = $this->attributes(3, 2);

        $this->assertTrue($attributes['isGroup']);
        $this->assertFalse($attributes['isManagedGroup']);
        $this->assertNull($attributes['actorRole']);
        $this->assertNull($attributes['ownerId']);
        $this->assertSame([], $attributes['roles']);
    }

    #[Test]
    public function our_group_is_still_managed()
    {
        $attributes = $this->attributes(1, 4);

        $this->assertTrue($attributes['isManagedGroup']);
        $this->assertSame('member', $attributes['actorRole']);
    }

    #[Test]
    public function nobody_manages_a_core_group_through_group_messages()
    {
        $this->assertSame(403, $this->call('POST', '/api/dialogs/3/settings', 2, ['title' => 'Mine now'])[0]);
        $this->assertSame(403, $this->call('POST', '/api/dialogs/3/participants', 2, ['userIds' => [5]])[0]);
        $this->assertSame(403, $this->call('POST', '/api/dialogs/3/remove-participant', 2, ['userId' => 3])[0]);
        $this->assertSame(403, $this->call('POST', '/api/dialogs/3/moderators', 2, ['userId' => 3])[0]);

        $this->assertSame([2, 3, 4], $this->participants(3));
        $this->assertSame(0, $this->database()->table('group_dialogs')->where('dialog_id', 3)->count());
    }

    #[Test]
    public function core_names_only_core_groups()
    {
        if (! method_exists(Dialog::class, 'isGroup')) {
            $this->markTestSkipped('Flarum 2.0 has no group names of its own.');
        }

        $this->setting('flarum-messages.allow_group_names', '1');

        $this->assertFalse($this->attributes(1, 2)['canRename'], 'Ours are renamed from Manage');
        $this->assertTrue($this->attributes(3, 2)['canRename']);

        $rename = fn (int $dialog) => $this->send($this->request('PATCH', "/api/dialogs/$dialog", [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'dialogs', 'id' => (string) $dialog, 'attributes' => ['name' => 'Renamed']]],
        ])->withAttribute('bypassThrottling', true))->getStatusCode();

        // Not writable there: refused, not ignored.
        $this->assertContains($rename(1), [400, 403, 422]);
        $this->assertNull($this->database()->table('dialogs')->where('id', 1)->value('name'));
        $this->assertSame('The squad', $this->attributes(1, 2)['title']);

        $this->assertSame(200, $rename(3));
        $this->assertSame('Renamed', $this->database()->table('dialogs')->where('id', 3)->value('name'));
    }
}
