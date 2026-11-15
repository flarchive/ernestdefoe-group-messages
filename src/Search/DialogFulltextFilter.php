<?php

namespace Ernestdefoe\GroupMessages\Search;

use Flarum\Search\AbstractFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchState;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the actor's conversations by who they are with, or by a group's name.
 * The searcher has already narrowed the query to conversations they can see.
 *
 * @extends AbstractFulltextFilter<DatabaseSearchState>
 */
class DialogFulltextFilter extends AbstractFulltextFilter
{
    public function search(SearchState $state, string $value): void
    {
        $value = trim($value);

        if ($value === '') {
            return;
        }

        // `%` and `_` are what they say. The escape character is spelled out:
        // SQLite has no default one, and `!` means the same on every driver.
        // Both sides lower-cased, as PostgreSQL's LIKE minds case.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value)).'%';
        $matches = fn (Builder $query, string $column) => $query->whereRaw('lower('.$query->getQuery()->getGrammar()->wrap($column).") like ? escape '!'", [$like]);
        $actorId = $state->getActor()->id;

        $state->getQuery()->where(function (Builder $query) use ($matches, $actorId) {
            $query
                ->whereHas('users', fn (Builder $users) => $matches($users->where('users.id', '!=', $actorId), 'users.username'))
                ->orWhereHas('groupDetail', fn (Builder $group) => $matches($group, 'group_dialogs.title'));
        });
    }
}
