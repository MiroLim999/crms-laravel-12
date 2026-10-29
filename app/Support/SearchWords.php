<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A typed search, split into words that match in any order.
 *
 * Every word has to appear somewhere in the value, so "Cruz Juan" finds
 * "Juan Dela Cruz". Words are matched literally: % and _ are not wildcards.
 */
final class SearchWords
{
    /**
     * The words in a search: split at spaces and commas, without repeats.
     * Stray punctuation such as a lone "-" is dropped, unless it is all there is.
     *
     * @return list<string>
     */
    public static function from(?string $search): array
    {
        $search = trim((string) $search);
        $words = preg_split('/[\s,]+/u', $search, -1, PREG_SPLIT_NO_EMPTY);

        // Not valid UTF-8, so it cannot be split: search for it as typed.
        if ($words === false) {
            return $search === '' ? [] : [$search];
        }

        $meaningful = array_filter($words, fn (string $word) => preg_match('/[\pL\pN]/u', $word) === 1);
        $unique = [];
        foreach ($meaningful ?: $words as $word) {
            $unique[mb_strtolower($word)] ??= $word;
        }

        return array_values($unique);
    }

    /**
     * Keep the rows whose column contains every word.
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  list<string>  $words
     */
    public static function whereContainsAll(Builder|Relation $query, string $column, array $words): void
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;
        $column = $builder->getQuery()->getGrammar()->wrap($builder->qualifyColumn($column));

        foreach ($words as $word) {
            // "!" rather than "\" as the escape character: it means the same
            // in every database, and needs no escaping inside the SQL string.
            $builder->whereRaw(
                "{$column} like ? escape '!'",
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%'],
            );
        }
    }
}
