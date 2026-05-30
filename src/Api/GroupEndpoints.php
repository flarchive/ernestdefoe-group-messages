<?php

namespace Ernestdefoe\GroupMessages\Api;

use Ernestdefoe\GroupMessages\GroupDialogManager;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Messages\Dialog;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * Custom endpoints layered onto flarum/messages' DialogResource (wired in
 * extend.php via Extend\ApiResource->endpoints). Each management action takes
 * the dialog as {id} (so $context->model resolves it and ->can() runs
 * GroupDialogPolicy against it) and returns the mutated Dialog, which the
 * resource serializes back to the client.
 */
class GroupEndpoints
{
    /**
     * @return list<Endpoint\Endpoint>
     */
    public static function get(): array
    {
        return [
            // Create a group. Mirrors the DM gate (sendAnyMessage); the
            // participant minimum is enforced in the manager.
            Endpoint\Endpoint::make('group-messages.create')
                ->route('POST', '/group')
                ->authenticated()
                ->action(function (Context $context) {
                    $actor = $context->getActor();
                    $actor->assertCan('sendAnyMessage');

                    $attrs = static::attributes($context);

                    $dialog = static::manager()->create(
                        $actor,
                        static::ids($attrs['userIds'] ?? []),
                        $attrs['title'] ?? null,
                        $attrs['iconUrl'] ?? null,
                    );

                    // This endpoint has no {id} route segment, so the framework
                    // can't auto-serialize a returned model. Return a minimal
                    // resource identifier; the client refetches the full dialog
                    // via the standard Show endpoint (and posts the first
                    // message through flarum/messages' message endpoint).
                    return ['data' => ['type' => 'dialogs', 'id' => (string) $dialog->id]];
                }),

            Endpoint\Endpoint::make('group-messages.settings')
                ->route('POST', '/{id}/settings')
                ->authenticated()
                ->can('editGroup')
                ->action(function (Context $context) {
                    /** @var Dialog $dialog */
                    $dialog = $context->model;
                    $attrs = static::attributes($context);
                    $manager = static::manager();

                    if (array_key_exists('title', $attrs)) {
                        $manager->rename($dialog, $attrs['title']);
                    }
                    if (array_key_exists('iconUrl', $attrs)) {
                        $manager->setIcon($dialog, $attrs['iconUrl']);
                    }

                    return $dialog->refresh();
                }),

            Endpoint\Endpoint::make('group-messages.addParticipants')
                ->route('POST', '/{id}/participants')
                ->authenticated()
                ->can('editGroup')
                ->action(function (Context $context) {
                    $context->getActor()->assertCan('sendAnyMessage');
                    /** @var Dialog $dialog */
                    $dialog = $context->model;
                    $ids = static::ids(static::attributes($context)['userIds'] ?? []);
                    static::manager()->addParticipants($dialog, $ids);

                    return $dialog->refresh();
                }),

            Endpoint\Endpoint::make('group-messages.removeParticipant')
                ->route('POST', '/{id}/remove-participant')
                ->authenticated()
                ->can('editGroup')
                ->action(function (Context $context) {
                    /** @var Dialog $dialog */
                    $dialog = $context->model;
                    $manager = static::manager();
                    $target = static::targetUser($context);

                    $role = $manager->roleOf($dialog, $target);
                    if ($role === GroupDialogManager::ROLE_OWNER) {
                        // The owner leaves via /leave (with succession); they
                        // can't be removed by anyone.
                        throw new PermissionDeniedException();
                    }
                    if ($role === GroupDialogManager::ROLE_MODERATOR
                        && ! $manager->isOwner($dialog, $context->getActor())) {
                        throw new PermissionDeniedException();
                    }

                    $manager->removeParticipant($dialog, $target);

                    return $dialog->refresh();
                }),

            Endpoint\Endpoint::make('group-messages.leave')
                ->route('POST', '/{id}/leave')
                ->authenticated()
                ->can('leaveGroup')
                ->action(function (Context $context) {
                    /** @var Dialog $dialog */
                    $dialog = $context->model;
                    static::manager()->leave($dialog, $context->getActor());

                    return $dialog;
                }),

            Endpoint\Endpoint::make('group-messages.addModerator')
                ->route('POST', '/{id}/moderators')
                ->authenticated()
                ->can('manageModerators')
                ->action(function (Context $context) {
                    /** @var Dialog $dialog */
                    $dialog = $context->model;
                    static::manager()->promoteModerator($dialog, static::targetUser($context));

                    return $dialog->refresh();
                }),

            Endpoint\Endpoint::make('group-messages.removeModerator')
                ->route('POST', '/{id}/remove-moderator')
                ->authenticated()
                ->can('manageModerators')
                ->action(function (Context $context) {
                    /** @var Dialog $dialog */
                    $dialog = $context->model;
                    static::manager()->demoteModerator($dialog, static::targetUser($context));

                    return $dialog->refresh();
                }),
        ];
    }

    protected static function manager(): GroupDialogManager
    {
        return resolve(GroupDialogManager::class);
    }

    /** @return array<string, mixed> */
    protected static function attributes(Context $context): array
    {
        return (array) Arr::get($context->body(), 'data.attributes', []);
    }

    /** @param mixed $raw @return int[] */
    protected static function ids($raw): array
    {
        return array_values(array_filter(array_map('intval', Arr::wrap($raw)), fn ($id) => $id > 0));
    }

    protected static function targetUser(Context $context): User
    {
        $id = (int) (static::attributes($context)['userId'] ?? 0);
        $user = $id > 0 ? User::query()->find($id) : null;
        if (! $user) {
            throw new BadRequestException('userId is required and must be a valid user.');
        }

        return $user;
    }
}
