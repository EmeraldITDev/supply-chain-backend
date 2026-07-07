<?php

namespace App\Http\Controllers\Concerns;

use App\Support\ListCountCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

trait ResolvesPaginatedLists
{
    protected function resolvePerPage(Request $request, int $default = 25, int $max = 100): int
    {
        return min($max, max(1, (int) $request->input('per_page', $request->input('limit', $default))));
    }

    protected function resolvePage(Request $request): int
    {
        return max(1, (int) $request->input('page', 1));
    }

    /**
     * @param  list<string>  $allowed
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    protected function resolveSort(
        Request $request,
        array $allowed,
        string $default = 'created_at',
        string $defaultDirection = 'desc',
    ): array {
        $sortBy = (string) $request->input('sort_by', $request->input('sortBy', $default));
        if (! in_array($sortBy, $allowed, true)) {
            $sortBy = $default;
        }

        $direction = strtolower((string) $request->input(
            'sort_direction',
            $request->input('sortOrder', $defaultDirection),
        ));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        return [$sortBy, $direction];
    }

    /**
     * @return array<string, int|null>
     */
    protected function paginationPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * @param  list<mixed>  $items
     * @return array{success: bool, data: list<mixed>, pagination: array<string, int|null>}
     */
    protected function paginatedJsonResponse(LengthAwarePaginator $paginator, array $items): array
    {
        return [
            'success' => true,
            'data' => array_values($items),
            'pagination' => $this->paginationPayload($paginator),
        ];
    }

    /**
     * Paginate with a cached total count (one fewer count(*) per request on warm cache).
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    protected function paginateWithCachedCount(
        Builder $query,
        Request $request,
        string $countScope,
        int $defaultPerPage = 25,
        int $maxPerPage = 100,
    ): LengthAwarePaginator {
        $perPage = $this->resolvePerPage($request, $defaultPerPage, $maxPerPage);
        $page = $this->resolvePage($request);
        $queryHash = md5($query->toSql().serialize($query->getBindings()));

        $total = ListCountCache::remember(
            $countScope,
            $queryHash,
            static fn (): int => (clone $query)->toBase()->getCountForPagination(),
        );

        $items = $query->forPage($page, $perPage)->get();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );
    }
}
