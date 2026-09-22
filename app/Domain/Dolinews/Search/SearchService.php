<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Search;

use App\Domain\Dolinews\Models\Article;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Free-text search over the feed and the sheets (SPEC 6.1).
 *
 * The structured filters answer "which announcements concern v22"; they
 * assume the reader already knows the slug of a project or of an editor.
 * A Dolibarr user knows neither: they know "Factur-X", "caisse",
 * "pointeuse". Without a plain search box the service is reachable only
 * by those who already know what it holds.
 *
 * Terms are ANDed, each one matched anywhere in the title, the summary
 * or the body: two words type a narrower question, not a longer one.
 *
 * No full-text index: the portable subset between SQLite (development)
 * and MySQL (production) is LIKE, and the feed is a few thousand rows.
 * The bound that keeps this honest is the term ceiling below, not an
 * index.
 */
class SearchService
{
    /**
     * Terms honoured in one query. Past this, the reader is pasting a
     * sentence, and every extra term is another full scan.
     */
    private const MAX_TERMS = 5;

    /**
     * Narrow an article query to the search terms.
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function applyToArticles(Builder $query, string $search): Builder
    {
        foreach ($this->terms($search) as $like) {
            $query->where(function (Builder $inner) use ($like): void {
                $this->orLike($inner, ['title', 'summary', 'body'], $like);
            });
        }

        return $query;
    }

    /**
     * Sheets matching the same terms, editor name included: an answer
     * naming the module is worth more than the announcement that
     * mentions it in passing, and a reader searching "cap-rel" means the
     * editor.
     *
     * @return Collection<int, Project>
     */
    public function projects(string $search, int $limit = 5): Collection
    {
        $terms = $this->terms($search);

        if ($terms === []) {
            return new Collection;
        }

        $query = Project::query()->with('editor');

        foreach ($terms as $like) {
            $query->where(function (Builder $inner) use ($like): void {
                $this->orLike($inner, ['name', 'slug', 'summary', 'description'], $like);

                $inner->orWhereIn(
                    'editor_id',
                    $this->orLike(Editor::query(), ['name', 'slug'], $like)->select('id'),
                );
            });
        }

        /** @var Collection<int, Project> $projects */
        $projects = $query->orderBy('name')->limit($limit)->get();

        return $projects;
    }

    /**
     * The escape character of the LIKE patterns.
     *
     * A backslash cannot be used here. Written as an SQL literal, the
     * clause reads "escape '\'", which MySQL parses as an unterminated
     * string: the backslash escapes the closing quote. SQLite accepts
     * it, so the whole suite stayed green while every search answered
     * with a syntax error in production. An ordinary character is
     * special to no engine.
     */
    private const ESCAPE = '!';

    /**
     * The escaped LIKE patterns of a raw search string, capped.
     *
     * @return list<string>
     */
    private function terms(string $search): array
    {
        $words = preg_split('/\s+/u', trim($search)) ?: [];

        $words = array_slice(
            array_values(array_filter($words, static fn (string $word): bool => $word !== '')),
            0,
            self::MAX_TERMS,
        );

        return array_map(
            // The wildcards of LIKE are escaped, not stripped: an
            // underscore is ordinary in a module name, and a reader
            // typing one means the character, not "any character".
            static fn (string $word): string => '%'.self::escape(mb_strtolower($word)).'%',
            $words,
        );
    }

    /**
     * One raw word turned into the literal part of a LIKE pattern.
     *
     * The escape character comes first: escaping it after the wildcards
     * would double the marks just written.
     */
    private static function escape(string $word): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $word,
        );
    }

    /**
     * OR the same pattern over several columns of one builder.
     *
     * lower() on both sides rather than the column collation: MySQL
     * matches case and accents insensitively by collation, SQLite does
     * not, and a test passing here has to mean something in production.
     * The remaining gap is an accented capital in the stored text under
     * SQLite, which its ASCII-only lower() leaves untouched.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    private function orLike(Builder $query, array $columns, string $like): Builder
    {
        foreach ($columns as $column) {
            $query->orWhereRaw("lower({$column}) like ? escape '".self::ESCAPE."'", [$like]);
        }

        return $query;
    }
}
