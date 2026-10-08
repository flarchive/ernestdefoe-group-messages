<?php

namespace Ernestdefoe\GroupMessages\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\GroupMessages\Tests\integration\GroupMessagesTestCase;
use Flarum\Messages\Access\MessagingPermission;
use Flarum\Messages\DialogMessageThrottler;
use PHPUnit\Framework\Attributes\Test;

/**
 * Who may be put in a group and who may write in one, alongside the rules
 * flarum/messages applies to its own conversations: recipients must exist,
 * and (from Flarum 2.0.0) be people who can reply, with a cap on how many
 * conversations a member opens in an hour.
 */
class MessagingRulesTest extends GroupMessagesTestCase
{
    private function requires2(string $class): void
    {
        if (! class_exists($class)) {
            $this->markTestSkipped("$class is new in flarum/messages 2.0.0.");
        }
    }

    /** Unconfirmed members have only guest permissions: they can't send messages. */
    private function cannotReply(int $user): void
    {
        $this->app();
        $this->database()->table('users')->where('id', $user)->update(['is_email_confirmed' => 0]);
    }

    private function sendTo(int $dialog, int $actor): int
    {
        return $this->send($this->request('POST', '/api/dialog-messages', [
            'authenticatedAs' => $actor,
            'json' => ['data' => [
                'type' => 'dialog-messages',
                'attributes' => ['content' => 'Hello'],
                'relationships' => ['dialog' => ['data' => ['type' => 'dialogs', 'id' => (string) $dialog]]],
            ]],
        ])->withAttribute('bypassThrottling', true))->getStatusCode();
    }

    #[Test]
    public function people_who_do_not_exist_cannot_be_put_in_a_group()
    {
        $this->assertSame(422, $this->call('POST', '/api/dialogs/group', 2, ['userIds' => [3, 999]])[0]);
        $this->assertSame(1, $this->database()->table('dialogs')->where('type', 'group')->count(), 'Nothing is written');

        $this->assertSame(422, $this->call('POST', '/api/dialogs/1/participants', 2, ['userIds' => [999]])[0]);
        $this->assertSame([2, 3, 4], $this->participants(1));
    }

    #[Test]
    public function people_who_cannot_reply_are_not_put_in_a_group()
    {
        $this->requires2(MessagingPermission::class);
        $this->cannotReply(5);

        $this->assertSame(422, $this->call('POST', '/api/dialogs/group', 2, ['userIds' => [3, 5]])[0]);
        $this->assertSame(422, $this->call('POST', '/api/dialogs/1/participants', 2, ['userIds' => [5]])[0]);
        $this->assertSame([2, 3, 4], $this->participants(1));

        // Unless the one adding them may message users without messaging permission.
        $this->assertSame(200, $this->call('POST', '/api/dialogs/group', 1, ['userIds' => [3, 5]])[0]);
    }

    #[Test]
    public function a_member_who_cannot_reply_does_not_silence_the_rest_of_the_group()
    {
        $this->requires2(MessagingPermission::class);
        $this->linkLastMessages();
        $this->cannotReply(4);

        [$status, $body] = $this->call('GET', '/api/dialogs/1', 2);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue($body['data']['attributes']['canSendMessage']);
        $this->assertSame(201, $this->sendTo(1, 2));

        // They still can't write themselves.
        [, $body] = $this->call('GET', '/api/dialogs/1', 4);
        $this->assertFalse($body['data']['attributes']['canSendMessage']);
        $this->assertSame(403, $this->sendTo(1, 4));

        // Direct conversations keep flarum/messages' own rule.
        $this->cannotReply(5);
        [, $body] = $this->call('GET', '/api/dialogs/2', 2);
        $this->assertFalse($body['data']['attributes']['canSendMessage']);
        $this->assertSame(403, $this->sendTo(2, 2));
    }

    #[Test]
    public function a_group_counts_towards_the_hourly_cap_on_new_conversations()
    {
        $this->requires2(DialogMessageThrottler::class);
        $this->app();

        foreach (range(100, 99 + DialogMessageThrottler::$newDialogsPerHour) as $id) {
            $this->database()->table('dialogs')->insert(['id' => $id, 'type' => 'direct', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
            $this->database()->table('dialog_user')->insert(['dialog_id' => $id, 'user_id' => 4, 'joined_at' => Carbon::now(), 'last_read_message_id' => 0]);
        }

        $this->assertSame(429, $this->call('POST', '/api/dialogs/group', 4, ['userIds' => [3, 5]])[0]);
        $this->assertSame(200, $this->call('POST', '/api/dialogs/group', 3, ['userIds' => [4, 5]])[0], 'Only for the member who opened them');
    }

    #[Test]
    public function a_direct_conversation_with_someone_who_has_left_the_forum_keeps_its_title()
    {
        $this->linkLastMessages();
        $this->database()->table('users')->where('id', 5)->delete();

        [$status, $body] = $this->call('GET', '/api/dialogs/2', 2);

        $this->assertSame(200, $status, json_encode($body));
        $translator = $this->app()->getContainer()->make('translator');
        $this->assertSame(
            $translator->trans('flarum-messages.lib.dialog.title', ['{username}' => $translator->trans('core.lib.username.deleted_text')]),
            $body['data']['attributes']['title']
        );
    }
}
