<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Closure;
use DateTimeInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class StatusStrip
{
    /**
     * @param  list<TextEntry>  $tiles
     * @return list<Section>
     */
    public static function make(array $tiles): array
    {
        if ($tiles === []) {
            return [];
        }

        return [
            Section::make()
                ->columns(min(count($tiles), 4))
                ->schema($tiles),
        ];
    }

    public static function badge(string $label, ?string $state, string|Closure|null $color = null): TextEntry
    {
        $entry = self::entry($label, $state)->badge();

        return $color === null ? $entry : $entry->color($color);
    }

    public static function fact(string $label, ?string $state, bool $copyable = false): TextEntry
    {
        $entry = self::entry($label, $state)->weight(FontWeight::SemiBold);

        return $copyable ? $entry->copyable() : $entry;
    }

    public static function moment(
        string $label,
        DateTimeInterface|string|null $moment,
        ?string $emptyText = null,
        bool $pastIsProblem = false,
    ): TextEntry {
        $moment = match (true) {
            $moment === null => null,
            is_string($moment) => Carbon::parse($moment),
            default => Carbon::instance($moment),
        };

        return self::entry($label, $moment?->diffForHumans(), $emptyText)
            ->weight(FontWeight::SemiBold)
            ->tooltip($moment?->toDateTimeString())
            ->color($pastIsProblem && $moment !== null && $moment->isPast() ? 'danger' : null);
    }

    private static function entry(string $label, ?string $state, ?string $emptyText = null): TextEntry
    {
        return TextEntry::make('strip_'.Str::slug($label, '_'))
            ->label($label)
            ->state($state)
            ->placeholder($emptyText ?? '—');
    }
}
