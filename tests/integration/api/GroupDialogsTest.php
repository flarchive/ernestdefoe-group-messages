<?php

namespace Ernestdefoe\GroupMessages\Tests\integration\api;

use Ernestdefoe\GroupMessages\Tests\integration\GroupMessagesTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Creating a group, what it serializes, and who may manage it. */
class GroupDialogsTest extends GroupMessagesTestCase
{
    private function dialog(int $id, int $actor): array
    {
        [$status, $body] = $this->call('GET', "/api/dialogs/$id", $actor);
        $this->assertSame(200, $status, json_encode($body));

        return $body['data']['attributes'];
    }

    #[Test]
    public function a_guest_cannot_create_a_group()
    {
        $this->assertSame(401, $this->call('POST', '/api/dialogs/group', null, ['userIds' => [3, 4]])[0]);
    }

    #[Test]
    public function a_member_who_may_not_message_cannot_create_one()
    {
        $this->app();
        $this->database()->table('group_permission')->where('permission', 'dialog.sendMessage')->delete();

        $this->assertSame(403, $this->call('POST', '/api/dialogs/group', 2, ['userIds' => [3, 4]])[0]);
    }

    #[Test]
    public function a_group_needs_two_other_people()
    {
        $this->assertSame(422, $this->call('POST', '/api/dialogs/group', 2, ['userIds' => [3, 2]])[0]);
    }

    #[Test]
    public function a_member_creates_a_group_they_own()
    {
        [$status, $body] = $this->call('POST', '/api/dialogs/group', 4, ['userIds' => [3, 5], 'title' => '  Road trip  ']);

        $this->assertSame(200, $status, json_encode($body));
        $id = (int) $body['data']['id'];

        $this->assertSame([3, 4, 5], $this->participants($id));
        $this->assertSame('group', $this->database()->table('dialogs')->where('id', $id)->value('type'));

        $attributes = $this->dialog($id, 4);
        $this->assertTrue($attributes['isGroup']);
        $this->assertSame('Road trip', $attributes['title']);
        $this->assertSame(4, $attributes['ownerId']);
        $this->assertSame('owner', $attributes['actorRole']);
        $this->assertSame(3, $attributes['participantCount']);
    }

    #[Test]
    public function a_group_serializes_its_roles_and_read_receipts()
    {
        $this->linkLastMessages();

        $attributes = $this->dialog(1, 4);

        $this->assertSame('The squad', $attributes['title']);
        $this->assertSame('member', $attributes['actorRole']);
        $this->assertSame(['2' => 'owner', '3' => 'moderator', '4' => 'member'], array_map('strval', $attributes['roles']));
        $this->assertEqualsCanonicalizing([2, 3], $attributes['lastMessageSeenByIds']);
    }

    #[Test]
    public function a_direct_conversation_serializes_as_before()
    {
        $attributes = $this->dialog(2, 2);

        $this->assertFalse($attributes['isGroup']);
        $this->assertNull($attributes['actorRole']);
        $this->assertSame([], $attributes['roles']);
        $translator = $this->app()->getContainer()->make(\Flarum\Locale\TranslatorInterface::class);
        $this->assertSame($translator->trans('flarum-messages.lib.dialog.title', ['{username}' => 'outsider']), $attributes['title'], 'flarum/messages\' own title, for the other person');
    }

    #[Test]
    public function the_owner_and_moderators_rename_it_and_members_do_not()
    {
        $title = fn () => $this->database()->table('group_dialogs')->where('dialog_id', 1)->value('title');

        $this->assertSame(403, $this->call('POST', '/api/dialogs/1/settings', 4, ['title' => 'By a member'])[0]);
        $this->assertSame('The squad', $title());

        $this->assertSame(200, $this->call('POST', '/api/dialogs/1/settings', 3, ['title' => 'By a moderator'])[0]);
        $this->assertSame('By a moderator', $title());
    }

    #[Test]
    public function group_management_never_applies_to_a_direct_conversation()
    {
        $this->assertSame(403, $this->call('POST', '/api/dialogs/2/settings', 2, ['title' => 'Now a group'])[0]);
        $this->assertSame(403, $this->call('POST', '/api/dialogs/2/participants', 2, ['userIds' => [3]])[0]);
        $this->assertSame(403, $this->call('POST', '/api/dialogs/2/leave', 5)[0], 'Nobody "leaves" a direct conversation');
        $this->assertSame([2, 5], $this->participants(2));
    }

    #[Test]
    public function managers_add_people()
    {
        $this->assertSame(403, $this->call('POST', '/api/dialogs/1/participants', 4, ['userIds' => [5]])[0]);
        $this->assertSame(200, $this->call('POST', '/api/dialogs/1/participants', 3, ['userIds' => [5]])[0]);

        $this->assertSame([2, 3, 4, 5], $this->participants(1));
    }

    #[Test]
    public function nobody_removes_the_owner_and_only_the_owner_removes_a_moderator()
    {
        $this->assertSame(403, $this->call('POST', '/api/dialogs/1/remove-participant', 3, ['userId' => 2])[0], 'Not the owner');
        $this->assertSame(403, $this->call('POST', '/api/dialogs/1/remove-participant', 4, ['userId' => 3])[0], 'Not by a member');

        $this->call('POST', '/api/dialogs/1/moderators', 2, ['userId' => 4]);
        $this->assertSame(403, $this->call('POST', '/api/dialogs/1/remove-participant', 3, ['userId' => 4])[0], 'A moderator cannot remove another');
        $this->call('POST', '/api/dialogs/1/remove-moderator', 2, ['userId' => 4]);

        $this->assertSame(200, $this->call('POST', '/api/dialogs/1/remove-participant', 3, ['userId' => 4])[0], 'A moderator removes a member');
        $this->assertSame(200, $this->call('POST', '/api/dialogs/1/remove-participant', 2, ['userId' => 3])[0], 'The owner removes a moderator');

        $this->assertSame([2], $this->participants(1));
        $this->assertSame(0, $this->database()->table('group_dialog_moderators')->where('dialog_id', 1)->count());
    }

    #[Test]
    public function only_the_owner_appoints_moderators()
    {
        $this->assertSame(403, $this->call('POST', '/api/dialogs/1/moderators', 3, ['userId' => 4])[0]);
        $this->assertSame(200, $this->call('POST', '/api/dialogs/1/moderators', 2, ['userId' => 4])[0]);
        $this->assertSame('moderator', $this->dialog(1, 4)['actorRole']);

        $this->assertSame(200, $this->call('POST', '/api/dialogs/1/remove-moderator', 2, ['userId' => 4])[0]);
        $this->assertSame('member', $this->dialog(1, 4)['actorRole']);
    }

    #[Test]
    public function when_the_owner_leaves_a_moderator_takes_over()
    {
        // 4 moderates instead of 3, so a moderator is not simply the next member.
        $this->app();
        $this->database()->table('group_dialog_moderators')->where('dialog_id', 1)->update(['user_id' => 4]);

        $this->assertSame(200, $this->call('POST', '/api/dialogs/1/leave', 2)[0]);

        $this->assertSame([3, 4], $this->participants(1));
        $this->assertSame(4, (int) $this->database()->table('group_dialogs')->where('dialog_id', 1)->value('owner_id'));
        $this->assertSame(0, $this->database()->table('group_dialog_moderators')->where('dialog_id', 1)->count(), 'The new owner is no longer listed as a moderator');
    }

    #[Test]
    public function when_the_last_person_leaves_the_group_is_gone()
    {
        $this->call('POST', '/api/dialogs/1/remove-participant', 2, ['userId' => 3]);
        $this->call('POST', '/api/dialogs/1/remove-participant', 2, ['userId' => 4]);
        $this->assertSame(200, $this->call('POST', '/api/dialogs/1/leave', 2)[0]);

        $this->assertSame(0, $this->database()->table('dialogs')->where('id', 1)->count());
    }

    #[Test]
    public function the_list_serializes_groups_without_a_query_per_dialog()
    {
        // More than flarum/messages 2.0's hourly cap on new conversations.
        foreach (range(3, 12) as $n) {
            $this->assertSame(200, $this->call('POST', '/api/dialogs/group', 4, ['userIds' => [2, 3, 5], 'title' => "Group $n"], bypassThrottling: true)[0]);
        }
        $this->linkLastMessages();

        // flarum/testing fails the request when the same query repeats.
        [$status, $body] = $this->call('GET', '/api/dialogs', 4);

        $this->assertSame(200, $status, json_encode($body));
        $this->assertCount(11, array_filter($body['data'], fn ($d) => $d['attributes']['isGroup']));
    }
}
