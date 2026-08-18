<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Accounting\Read\Cursor;
use App\Accounting\Read\ReadPage;
use App\Accounting\Read\ReadQuery;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;

final readonly class MirrorReader
{
    /** @return ReadPage<LedgerAccount> */
    public function ledgerAccounts(Connection $connection, ReadQuery $query): ReadPage
    {
        return $this->page(
            $connection,
            ConnectionAccountingRef::KIND_GL,
            $query,
            static fn (ConnectionAccountingRef $ref): LedgerAccount => new LedgerAccount(
                id: (string) $ref->native_id,
                code: (string) $ref->code,
                name: $ref->label,
                attributes: $ref->attrs ?? [],
            ),
        );
    }

    /** @return ReadPage<TaxCode> */
    public function taxCodes(Connection $connection, ReadQuery $query): ReadPage
    {
        return $this->page(
            $connection,
            ConnectionAccountingRef::KIND_VAT,
            $query,
            static function (ConnectionAccountingRef $ref): TaxCode {
                $attrs = $ref->attrs ?? [];
                $rate = $attrs['percentage'] ?? null;

                return new TaxCode(
                    id: (string) $ref->native_id,
                    code: (string) $ref->code,
                    name: $ref->label,
                    rate: $rate === null ? null : (float) $rate,
                    attributes: $attrs,
                );
            },
        );
    }

    /**
     * @template T
     *
     * @param  callable(ConnectionAccountingRef): T  $transform
     * @return ReadPage<T>
     */
    private function page(Connection $connection, string $kind, ReadQuery $query, callable $transform): ReadPage
    {
        $rows = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', $kind)
            ->when($query->cursor !== null, fn ($q) => $q->where('code', '>', $query->cursor->value))
            ->orderBy('code')
            ->limit($query->limit + 1)
            ->get();

        $hasMore = $rows->count() > $query->limit;
        $page = $hasMore ? $rows->take($query->limit) : $rows;

        return new ReadPage(
            items: array_values($page->map($transform)->all()),
            nextCursor: $hasMore ? Cursor::of((string) $page->last()?->code) : null,
        );
    }
}
