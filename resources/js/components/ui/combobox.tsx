import { Popover } from 'radix-ui';
import { useEffect, useId, useMemo, useState, type KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';

export interface ComboboxOption {
    code: string;
    label: string;
}

const MAX_RENDERED = 50;

function ComboboxList({
    options,
    value,
    onPick,
}: {
    options: ComboboxOption[];
    value: string | null;
    onPick: (code: string | null) => void;
}) {
    const listId = useId();
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(() => Math.max(0, options.slice(0, MAX_RENDERED).findIndex((o) => o.code === value) + 1));

    const index = useMemo(() => options.map((option) => ({ option, haystack: option.label.toLowerCase() })), [options]);

    const matches = useMemo(() => {
        const terms = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
        const all = terms.length === 0 ? index : index.filter(({ haystack }) => terms.every((term) => haystack.includes(term)));

        return { total: all.length, shown: all.slice(0, MAX_RENDERED).map(({ option }) => option) };
    }, [index, query]);

    const items: (ComboboxOption | null)[] = [null, ...matches.shown];
    const activeIndex = Math.min(active, items.length - 1);

    useEffect(() => {
        document.getElementById(`${listId}-${activeIndex}`)?.scrollIntoView({ block: 'nearest' });
    }, [listId, activeIndex]);

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive(Math.min(activeIndex + 1, items.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive(Math.max(activeIndex - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            onPick(items[activeIndex]?.code ?? null);
        }
    };

    return (
        <div className="flex flex-col">
            <input
                type="text"
                role="combobox"
                aria-expanded
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={`${listId}-${activeIndex}`}
                aria-label="Zoek op code of naam"
                placeholder="Zoek op code of naam"
                value={query}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setActive(event.target.value.trim() === '' ? 0 : 1);
                }}
                onKeyDown={onKeyDown}
                className="border-b border-border bg-card px-3 py-2.5 text-xs2 text-foreground outline-none placeholder:text-muted-foreground"
            />
            <ul id={listId} role="listbox" className="max-h-[280px] overflow-y-auto overscroll-contain py-1">
                {items.map((option, i) => {
                    const selected = option === null ? value === null : option.code === value;

                    return (
                        <li
                            key={option?.code ?? ''}
                            id={`${listId}-${i}`}
                            role="option"
                            aria-selected={selected}
                            onMouseDown={(event) => event.preventDefault()}
                            onMouseMove={() => setActive(i)}
                            onClick={() => onPick(option?.code ?? null)}
                            className={cn(
                                'cursor-pointer truncate px-3 py-2 text-xs2',
                                option === null ? 'text-muted-foreground' : 'font-data text-foreground',
                                i === activeIndex && 'bg-muted',
                                selected && 'font-semibold',
                            )}
                        >
                            {option === null ? '— Geen —' : option.label}
                        </li>
                    );
                })}
            </ul>
            {matches.total === 0 && <p className="px-3 pb-2.5 text-xs2 text-muted-foreground">Niets gevonden.</p>}
            {matches.total > MAX_RENDERED && (
                <p className="border-t border-border px-3 py-2 text-2xs text-muted-foreground">
                    Nog {matches.total - MAX_RENDERED} meer. Verfijn je zoekopdracht.
                </p>
            )}
        </div>
    );
}

/**
 * Doorzoekbare keuzelijst. De lijst bestaat alleen zolang de popover open is en
 * toont maximaal 50 treffers, zodat 30 velden met elk 500 opties licht blijven.
 */
export function Combobox({
    value,
    options,
    onChange,
    disabled = false,
    invalid = false,
    labelledBy,
    describedBy,
}: {
    value: string | null;
    options: ComboboxOption[];
    onChange: (code: string | null) => void;
    disabled?: boolean;
    invalid?: boolean;
    labelledBy?: string;
    describedBy?: string;
}) {
    const [open, setOpen] = useState(false);
    const selected = useMemo(() => options.find((option) => option.code === value) ?? null, [options, value]);
    const empty = options.length === 0;

    return (
        // Geen Portal: binnen de modale drawer blokkeert diens scroll-lock anders het scrollen in de lijst.
        <Popover.Root open={open} onOpenChange={setOpen}>
            <Popover.Trigger asChild>
                <button
                    type="button"
                    disabled={disabled || empty}
                    aria-haspopup="listbox"
                    aria-labelledby={labelledBy}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                            event.preventDefault();
                            setOpen(true);
                        }
                    }}
                    className={cn(
                        'flex w-full min-w-0 items-center justify-between gap-2 rounded-md border bg-card px-3 py-2.5 text-left text-xs2 transition-colors',
                        'hover:border-brand focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                        'disabled:cursor-not-allowed disabled:bg-muted disabled:hover:border-border',
                        invalid ? 'border-error' : 'border-border',
                    )}
                >
                    <span className={cn('truncate', value === null ? 'text-muted-foreground' : 'font-data text-foreground')}>
                        {empty ? 'Nog niets gesynchroniseerd.' : selected ? selected.label : (value ?? '— Geen —')}
                    </span>
                    {!disabled && !empty && (
                        <span aria-hidden className="shrink-0 text-2xs text-muted-foreground">
                            ▾
                        </span>
                    )}
                </button>
            </Popover.Trigger>
            <Popover.Content
                align="start"
                sideOffset={4}
                className="z-50 w-(--radix-popover-trigger-width) min-w-64 overflow-hidden rounded-md border border-border bg-card shadow-card"
            >
                <ComboboxList
                    options={options}
                    value={value}
                    onPick={(code) => {
                        onChange(code);
                        setOpen(false);
                    }}
                />
            </Popover.Content>
        </Popover.Root>
    );
}
