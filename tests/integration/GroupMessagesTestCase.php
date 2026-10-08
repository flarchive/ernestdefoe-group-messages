<?php

namespace Ernestdefoe\GroupMessages\Tests\integration;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;

/**
 * Users: 1 admin, 2 owner, 3 moderator, 4 member, 5 outsider.
 * Dialog 1: a group (2 owns it, 3 moderates, 4 is a member) with message 10
 *   by 2. Users 2 and 3 have read it.
 * Dialog 2: a direct conversation between 2 and 5, with message 20.
 */
abstract class GroupMessagesTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        // Tags too: flarum/messages serializes a message with its tag mentions,
        // and without the tags resource every message request fails.
        $this->extension('flarum-tags', 'flarum-messages', 'ernestdefoe-group-messages');

        $user = fn (int $id, string $name) => ['id' => $id, 'username' => $name, 'email' => "$name@machine.local", 'is_email_confirmed' => 1];
        $member = fn (int $dialog, int $user, int $read = 0) => ['dialog_id' => $dialog, 'user_id' => $user, 'joined_at' => Carbon::now(), 'last_read_message_id' => $read];

        // Messages before the dialogs that point at them: MySQL enforces the keys.
        $this->prepareDatabase([
            User::class => [$user(2, 'owner'), $user(3, 'moderator'), $user(4, 'member'), $user(5, 'outsider')],
            'dialogs' => [
                ['id' => 1, 'type' => 'group', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
                ['id' => 2, 'type' => 'direct', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ],
            'dialog_messages' => [
                ['id' => 10, 'dialog_id' => 1, 'number' => 1, 'user_id' => 2, 'content' => '<t><p>Welcome</p></t>', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
                ['id' => 20, 'dialog_id' => 2, 'number' => 1, 'user_id' => 2, 'content' => '<t><p>Hi</p></t>', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ],
            'dialog_user' => [$member(1, 2, 10), $member(1, 3, 10), $member(1, 4), $member(2, 2), $member(2, 5)],
            'group_dialogs' => [
                ['dialog_id' => 1, 'title' => 'The squad', 'icon_url' => null, 'owner_id' => 2, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ],
            'group_dialog_moderators' => [['dialog_id' => 1, 'user_id' => 3]],
        ]);
    }

    /** Fixtures can't set these at insert: the messages they point at come later. */
    protected function linkLastMessages(): void
    {
        $this->app();
        $this->database()->table('dialogs')->where('id', 1)->update(['first_message_id' => 10, 'last_message_id' => 10, 'last_message_at' => Carbon::now()]);
        $this->database()->table('dialogs')->where('id', 2)->update(['first_message_id' => 20, 'last_message_id' => 20, 'last_message_at' => Carbon::now()]);
    }

    /** @return array{0: int, 1: mixed} */
    protected function call(string $method, string $path, ?int $actor = null, ?array $attributes = null, array $query = [], bool $bypassThrottling = false): array
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($attributes !== null) {
            $options['json'] = ['data' => ['attributes' => $attributes]];
        }

        $request = $this->request($method, $path, $options)->withQueryParams($query);

        if (! $actor && $method !== 'GET') {
            $session = $this->send($this->request('GET', '/api'));
            $request = $this->request($method, $path, $options + ['cookiesFrom' => $session])->withHeader('X-CSRF-Token', $session->getHeaderLine('X-CSRF-Token'));
        }

        $response = $this->send($request->withAttribute('bypassThrottling', $bypassThrottling));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    protected function participants(int $dialog): array
    {
        return $this->database()->table('dialog_user')->where('dialog_id', $dialog)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }
}
