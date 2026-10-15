<?php

namespace Ernestdefoe\GroupMessages\Tests\integration\api;

use Ernestdefoe\GroupMessages\Tests\integration\GroupMessagesTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Reactions and replies on a conversation's messages. */
class MessagesTest extends GroupMessagesTestCase
{
    private function reactions(int $message, int $actor): array
    {
        [$status, $body] = $this->call('GET', "/api/dialog-messages/$message", $actor);
        $this->assertSame(200, $status, json_encode($body));

        return $body['data']['attributes']['reactions'];
    }

    #[Test]
    public function a_participant_reacts_once_per_emoji_and_can_take_it_back()
    {
        $this->assertSame(200, $this->call('POST', '/api/dialog-messages/10/react', 4, ['reaction' => '👍'])[0]);
        $this->call('POST', '/api/dialog-messages/10/react', 4, ['reaction' => '👍']);
        $this->call('POST', '/api/dialog-messages/10/react', 3, ['reaction' => '👍']);

        $this->assertSame([['reaction' => '👍', 'count' => 2, 'mine' => true]], $this->reactions(10, 4));
        $this->assertSame([['reaction' => '👍', 'count' => 2, 'mine' => false]], $this->reactions(10, 2));

        $this->assertSame(200, $this->call('POST', '/api/dialog-messages/10/unreact', 4, ['reaction' => '👍'])[0]);
        $this->assertSame([['reaction' => '👍', 'count' => 1, 'mine' => false]], $this->reactions(10, 4));
    }

    #[Test]
    public function only_a_participant_may_react()
    {
        $this->assertSame(404, $this->call('POST', '/api/dialog-messages/10/react', null, ['reaction' => '👍'])[0], 'A guest cannot see it');
        $this->assertSame(404, $this->call('POST', '/api/dialog-messages/10/react', 5, ['reaction' => '👍'])[0]);
        $this->assertSame(0, $this->database()->table('dialog_message_reactions')->count());
    }

    #[Test]
    public function a_reaction_must_be_given()
    {
        $this->assertSame(400, $this->call('POST', '/api/dialog-messages/10/react', 4, ['reaction' => '  '])[0]);
        $this->assertSame(400, $this->call('POST', '/api/dialog-messages/10/react', 4, ['reaction' => str_repeat('x', 61)])[0]);
    }

    #[Test]
    public function a_reply_links_only_to_a_message_in_the_same_conversation()
    {
        $send = fn (int $replyTo) => $this->send($this->request('POST', '/api/dialog-messages', [
            'authenticatedAs' => 4,
            'json' => ['data' => [
                'type' => 'dialog-messages',
                'attributes' => ['content' => 'Replying', 'replyToId' => $replyTo],
                'relationships' => ['dialog' => ['data' => ['type' => 'dialogs', 'id' => '1']]],
            ]],
            // Two messages inside flarum/messages 2.0's ten seconds.
        ])->withAttribute('bypassThrottling', true));

        $response = $send(10);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(201, $response->getStatusCode(), json_encode($body));
        $this->assertSame(10, $body['data']['attributes']['replyToId']);

        $response = $send(20);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(201, $response->getStatusCode(), json_encode($body));
        $this->assertNull($body['data']['attributes']['replyToId'], 'Message 20 is in another conversation');
    }

    #[Test]
    public function a_conversations_messages_list_their_reactions_and_replies_without_a_query_per_message()
    {
        $this->app();
        foreach (range(11, 19) as $id) {
            $this->database()->table('dialog_messages')->insert(['id' => $id, 'dialog_id' => 1, 'number' => $id - 9, 'user_id' => 3, 'content' => '<t><p>Message '.$id.'</p></t>', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $this->database()->table('dialog_message_reactions')->insert(['message_id' => $id, 'user_id' => 4, 'reaction' => '🔥', 'created_at' => date('Y-m-d H:i:s')]);
            $this->database()->table('dialog_message_replies')->insert(['message_id' => $id, 'reply_to_id' => 10]);
        }

        // flarum/testing fails the request when the same query repeats.
        [$status, $body] = $this->call('GET', '/api/dialog-messages', 4, null, ['filter' => ['dialog' => '1']]);

        $this->assertSame(200, $status, json_encode($body));
        $replied = array_filter($body['data'], fn ($m) => $m['attributes']['replyToId'] === 10);
        $this->assertCount(9, $replied);
        foreach ($replied as $message) {
            $this->assertSame([['reaction' => '🔥', 'count' => 1, 'mine' => true]], $message['attributes']['reactions']);
        }
    }
}
