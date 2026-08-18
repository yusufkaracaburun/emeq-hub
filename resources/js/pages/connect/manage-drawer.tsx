import { router, useHttp } from '@inertiajs/react';
import { Dialog } from 'radix-ui';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type MatchedOn = 'kvk' | 'vat' | 'name' | 'created' | 'pinned';

interface RefOption {
    code: string;
    label: string;
}

interface RelationRow {
    id: number;
    code: string;
    label: string | null;
    native_id: string;
    matched_on: MatchedOn | null;
    synced_at: string | null;
    relink_url: string;
    unlink_url: string;
}

interface ManagePayload {
    connection: {
        provider: string;
        label: string;
        status: string;
        administratie_id: string | null;
        connected_since: string | null;
        reconnect_url: string;
        disconnect_url: string;
    };
    bookings: BookingRow[];
    relations: RelationRow[];
    settings: {
        journals: { sales: string | null; purchase: string | null; options: RefOption[] };
        gl_accounts: { sales_default: string | null; purchase_default: string | null; options: RefOption[] };
    };
    urls: { mapping_url: string; relations_search_url: string };
}

interface BookingRow {
    booked_at: string | null;
    document: string | null;
    posted: boolean;
    messages: string[];
}

interface SearchResult {
    id: string;
    code: string;
    name: string;
}

type Tab = 'bookings' | 'relations' | 'settings';

const TAB_HINTS: Record<Tab, (app: string) => string> = {
    bookings: (app) => `Wat ${app} de afgelopen dagen naar je administratie heeft gestuurd.`,
    relations: (app) => `Zo weet je boekhouding welke klant of leverancier uit ${app} bij welke relatie hoort.`,
    settings: (app) => `Zo boekt ${app} in je administratie. Wijzig je iets, dan geldt het vanaf de volgende boeking.`,
};

const TABS: { key: Tab; label: string }[] = [
    { key: 'bookings', label: 'Boekingen' },
    { key: 'relations', label: 'Klanten en leveranciers' },
    { key: 'settings', label: 'Instellingen' },
];

const MATCHED_ON_LABELS: Record<MatchedOn, string> = {
    kvk: 'KVK',
    vat: 'BTW',
    name: 'NAAM — CONTROLEER',
    created: 'NIEUW AANGEMAAKT',
    pinned: 'HANDMATIG GEKOPPELD',
};

const CONNECTION_STATUS_LABELS: Record<string, string> = {
    active: 'Actief',
    pending: 'Bezig',
    needs_consent: 'Nieuwe toestemming nodig',
};

function MatchedOnPill({ matchedOn }: { matchedOn: MatchedOn | null }) {
    if (matchedOn === null) {
        return null;
    }

    const isWarning = matchedOn === 'name';

    return (
        <span
            className={cn(
                'rounded-full px-2 py-[2px] font-mono text-2xs uppercase tracking-[1px]',
                isWarning ? 'bg-warning/15 text-warning' : 'bg-muted text-muted-foreground',
            )}
        >
            {MATCHED_ON_LABELS[matchedOn]}
        </span>
    );
}

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Boekingen krijgt bewust geen data: `pass_through_calls.response_body` (waar de
 * melding — inclusief `warnings[]` — in zou staan) wordt alleen gevuld bij een
 * fout (status >= 400). Voor een geslaagde boeking is er dus geen melding om te
 * tonen. Zie het sessierapport voor de volledige onderbouwing.
 */
function BookingsTab({ bookings }: { bookings: BookingRow[] }) {
    if (bookings.length === 0) {
        return (
            <p className="rounded-lg bg-muted px-5 py-8 text-center text-xs2 text-muted-foreground">
                Nog niets geboekt. Zodra er een factuur of bon naar je administratie gaat, staat 'ie hier.
            </p>
        );
    }

    return (
        <div className="overflow-hidden rounded-lg border border-border">
            {bookings.map((booking, index) => (
                <div
                    key={`${booking.booked_at}-${index}`}
                    className={`flex items-start gap-3 px-4 py-3 ${index === bookings.length - 1 ? '' : 'border-b border-border'}`}
                >
                    <span className="w-20 shrink-0 pt-0.5 text-xs2 text-muted-foreground">
                        {formatBookedAt(booking.booked_at)}
                    </span>
                    <span className="w-44 shrink-0 pt-0.5 text-xs2 font-semibold text-foreground">
                        {booking.document ?? '—'}
                    </span>
                    <span className="shrink-0">
                        <StatusPill posted={booking.posted} />
                    </span>
                    <span className="flex-1 text-xs2 text-muted-foreground">
                        {booking.messages.length === 0 ? '—' : booking.messages.join(' · ')}
                    </span>
                </div>
            ))}
        </div>
    );
}

function StatusPill({ posted }: { posted: boolean }) {
    return (
        <span
            className={`rounded-full px-2 py-0.5 font-caption text-2xs tracking-wide ${
                posted ? 'bg-success-soft text-success' : 'bg-error-soft text-error-deep'
            }`}
        >
            {posted ? 'GEBOEKT' : 'GEWEIGERD'}
        </span>
    );
}

function formatBookedAt(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' });
}

function RelationRowView({
    relation,
    searchUrl,
    onChanged,
}: {
    relation: RelationRow;
    searchUrl: string;
    onChanged: (updated: RelationRow | null, id: number) => void;
}) {
    const [searching, setSearching] = useState(false);
    const search = useHttp<{ q: string }, { results: SearchResult[] }>({ q: '' });
    const relink = useHttp<{ native_id: string; label: string }, { relation: RelationRow }>({
        native_id: '',
        label: '',
    });
    const unlink = useHttp({});

    // `useHttp` verstuurt zijn eigen reactieve `data`; `setData` ís React-state en
    // is nog niet bijgewerkt in dezelfde tick als de klik zelf. Een pending-vlag +
    // effect wacht op de eerstvolgende render, waar `relink.data` de nieuwe
    // waarde al draagt, vóór de patch daadwerkelijk verstuurd wordt.
    const [pendingPick, setPendingPick] = useState(false);

    useEffect(() => {
        if (!pendingPick) {
            return;
        }

        setPendingPick(false);
        relink.patch(relation.relink_url, {
            onSuccess: (data) => {
                onChanged(data.relation, relation.id);
                setSearching(false);
            },
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pendingPick]);

    const pick = (result: SearchResult) => {
        relink.setData({ native_id: result.id, label: result.name });
        setPendingPick(true);
    };

    const removeLink = () => {
        unlink.delete(relation.unlink_url, {
            onSuccess: () => onChanged(null, relation.id),
        });
    };

    return (
        <div className="flex flex-col gap-3 rounded-lg border border-border bg-card px-4 py-3.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-col gap-[3px]">
                    <span className="text-sm font-semibold text-foreground">{relation.label ?? relation.code}</span>
                    <span className="font-mono text-2xs text-muted-foreground">{relation.code}</span>
                </div>
                <MatchedOnPill matchedOn={relation.matched_on} />
            </div>

            <div className="flex flex-wrap gap-2">
                <Button type="button" size="sm" variant="outline" onClick={() => setSearching((v) => !v)}>
                    Herkoppelen
                </Button>
                <Button type="button" size="sm" variant="outline" onClick={removeLink} disabled={unlink.processing}>
                    {unlink.processing ? 'Bezig…' : 'Ontkoppelen'}
                </Button>
            </div>

            {searching && (
                <div className="flex flex-col gap-2 border-t border-border pt-3">
                    <p className="text-xs2 text-muted-foreground">
                        Typ de volledige bedrijfsnaam zoals die in de administratie staat.
                    </p>
                    <div className="flex gap-2">
                        <input
                            type="text"
                            value={search.data.q}
                            onChange={(e) => search.setData('q', e.target.value)}
                            className="w-full rounded-md border border-border bg-card px-3 py-2 text-sm text-foreground"
                            placeholder="Bedrijfsnaam"
                        />
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => search.get(searchUrl)}
                            disabled={search.processing || search.data.q.trim() === ''}
                        >
                            {search.processing ? 'Zoeken…' : 'Zoeken'}
                        </Button>
                    </div>

                    {search.wasSuccessful && search.response?.results.length === 0 && (
                        <p className="text-xs2 text-muted-foreground">Geen relatie gevonden op die naam.</p>
                    )}

                    {(search.response?.results ?? []).map((result) => (
                        <button
                            key={result.id}
                            type="button"
                            onClick={() => pick(result)}
                            disabled={relink.processing}
                            className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-left text-xs2 hover:border-brand hover:bg-muted"
                        >
                            <span>{result.name}</span>
                            <span className="text-muted-foreground">Koppel →</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function RelationsTab({ payload, onRelationsChange }: { payload: ManagePayload; onRelationsChange: (relations: RelationRow[]) => void }) {
    if (payload.relations.length === 0) {
        return (
            <p className="rounded-lg bg-muted px-5 py-8 text-center text-xs2 text-muted-foreground">
                Nog geen relaties gekoppeld. Ze verschijnen hier zodra een boeking er één herkent of aanmaakt.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            {payload.relations.map((relation) => (
                <RelationRowView
                    key={relation.id}
                    relation={relation}
                    searchUrl={payload.urls.relations_search_url}
                    onChanged={(updated, id) => {
                        onRelationsChange(
                            updated === null
                                ? payload.relations.filter((r) => r.id !== id)
                                : payload.relations.map((r) => (r.id === id ? updated : r)),
                        );
                    }}
                />
            ))}
        </div>
    );
}

function RefSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string | null;
    options: RefOption[];
    onChange: (value: string) => void;
}) {
    return (
        <label className="flex flex-col gap-1.5">
            <span className="text-xs2 font-semibold text-foreground">{label}</span>
            {options.length === 0 ? (
                <span className="text-xs2 text-muted-foreground">Nog niets gesynchroniseerd.</span>
            ) : (
                <select
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    className="rounded-md border border-border bg-card px-3 py-2 text-sm text-foreground"
                >
                    <option value="">— Kies —</option>
                    {options.map((option) => (
                        <option key={option.code} value={option.code}>
                            {option.label}
                        </option>
                    ))}
                </select>
            )}
        </label>
    );
}

function SettingsTab({ payload, onSaved }: { payload: ManagePayload; onSaved: (settings: ManagePayload['settings']) => void }) {
    const mapping = useHttp<
        { journals: { sales: string; purchase: string }; gl_accounts: { sales_default: string; purchase_default: string } },
        { settings: ManagePayload['settings'] }
    >({
        journals: { sales: payload.settings.journals.sales ?? '', purchase: payload.settings.journals.purchase ?? '' },
        gl_accounts: {
            sales_default: payload.settings.gl_accounts.sales_default ?? '',
            purchase_default: payload.settings.gl_accounts.purchase_default ?? '',
        },
    });

    const save = () => {
        mapping.put(payload.urls.mapping_url, {
            onSuccess: (data) => onSaved(data.settings),
        });
    };

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-3">
                <p className="text-sm font-semibold text-foreground">Dagboeken</p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <RefSelect
                        label="Verkoop"
                        value={mapping.data.journals.sales}
                        options={payload.settings.journals.options}
                        onChange={(value) => mapping.setData('journals', { ...mapping.data.journals, sales: value })}
                    />
                    <RefSelect
                        label="Inkoop"
                        value={mapping.data.journals.purchase}
                        options={payload.settings.journals.options}
                        onChange={(value) => mapping.setData('journals', { ...mapping.data.journals, purchase: value })}
                    />
                </div>
            </div>

            <div className="flex flex-col gap-3">
                <p className="text-sm font-semibold text-foreground">Standaard grootboekrekeningen</p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <RefSelect
                        label="Omzet (verkoop)"
                        value={mapping.data.gl_accounts.sales_default}
                        options={payload.settings.gl_accounts.options}
                        onChange={(value) => mapping.setData('gl_accounts', { ...mapping.data.gl_accounts, sales_default: value })}
                    />
                    <RefSelect
                        label="Kosten (inkoop)"
                        value={mapping.data.gl_accounts.purchase_default}
                        options={payload.settings.gl_accounts.options}
                        onChange={(value) => mapping.setData('gl_accounts', { ...mapping.data.gl_accounts, purchase_default: value })}
                    />
                </div>
            </div>

            <div className="flex flex-col gap-1.5 rounded-lg bg-muted px-4 py-3">
                <span className="text-xs2 font-semibold text-foreground">BTW-codes</span>
                <span className="text-xs2 text-muted-foreground">
                    Automatisch — de Hub leidt de juiste BTW-code per tarief zelf af. Klopt dat niet, pas het aan in{' '}
                    {payload.connection.label}.
                </span>
            </div>

            <div className="flex items-center gap-3">
                <Button type="button" size="sm" onClick={save} disabled={mapping.processing}>
                    {mapping.processing ? 'Opslaan…' : 'Opslaan'}
                </Button>
                {mapping.recentlySuccessful && <span className="text-xs2 text-success">Opgeslagen</span>}
            </div>
        </div>
    );
}

export function ConnectManageDrawer({
    app,
    provider,
    open,
    onOpenChange,
}: {
    app: string;
    provider: { key: string; label: string; manage_url: string | null } | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [payload, setPayload] = useState<ManagePayload | null>(null);
    const [tab, setTab] = useState<Tab>('bookings');
    const fetchPayload = useHttp<Record<string, never>, ManagePayload>({});

    useEffect(() => {
        if (!open || !provider?.manage_url) {
            return;
        }

        setPayload(null);
        setTab('bookings');
        fetchPayload.get(provider.manage_url, { onSuccess: (data) => setPayload(data) });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, provider?.manage_url]);

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-40 bg-overlay" />
                <Dialog.Content
                    className={cn(
                        'fixed z-50 flex flex-col gap-5 overflow-y-auto bg-card p-6 shadow-card',
                        'inset-x-0 bottom-0 max-h-[85vh] rounded-t-2xl',
                        'sm:inset-y-0 sm:right-0 sm:left-auto sm:max-h-none sm:w-full sm:max-w-[760px] sm:rounded-none sm:rounded-l-2xl',
                    )}
                >
                    <div className="flex items-center justify-between gap-3">
                        <Dialog.Close asChild>
                            <button
                                type="button"
                                className="flex items-center gap-2 text-sm font-semibold text-muted-foreground transition hover:text-foreground"
                            >
                                <span aria-hidden>←</span> Je koppelingen
                            </button>
                        </Dialog.Close>
                        <Dialog.Close asChild>
                            <Button type="button" size="sm" variant="ghost" aria-label="Sluiten">
                                ✕
                            </Button>
                        </Dialog.Close>
                    </div>

                    <div className="flex flex-col gap-2">
                        <span className="font-caption text-xs uppercase tracking-[2px] text-brand">Koppeling beheren</span>
                        <Dialog.Title className="text-2xl font-bold leading-tight text-foreground">
                            {payload?.connection.label ?? provider?.label}
                        </Dialog.Title>
                        <Dialog.Description className="text-sm leading-relaxed text-muted-foreground">
                            Hieronder zie je wat {app} met je administratie uitwisselt. Klopt er iets niet, dan herstel je
                            het hier.
                        </Dialog.Description>
                    </div>

                    {payload && (
                        <div className="flex flex-col gap-1 rounded-lg border border-border bg-card px-5 py-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-md font-semibold text-foreground">
                                    {payload.connection.administratie_id === null
                                        ? payload.connection.label
                                        : `Administratie ${payload.connection.administratie_id}`}
                                </span>
                                <span className="rounded-full bg-success-soft px-2 py-[2px] font-caption text-2xs uppercase tracking-[1px] text-success">
                                    {CONNECTION_STATUS_LABELS[payload.connection.status] ?? payload.connection.status}
                                </span>
                            </div>
                            <span className="text-xs2 text-muted-foreground">
                                Gekoppeld sinds {formatDate(payload.connection.connected_since)}
                            </span>
                        </div>
                    )}

                    {payload && (
                        <div className="flex flex-wrap gap-2">
                            {/*
                                Zelfde routes als de rij op de picker-pagina: `connect.start` is
                                een POST (Inertia::location naar de partner-authorize-URL),
                                `connect.disconnect` een DELETE. `window.location.href` zou hier
                                een GET afvuren en op beide een 405 opleveren.
                            */}
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => router.post(payload.connection.reconnect_url, {}, { preserveScroll: true })}
                            >
                                Opnieuw koppelen
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    if (window.confirm(`${payload.connection.label} ontkoppelen? De toegang wordt ingetrokken.`)) {
                                        router.delete(payload.connection.disconnect_url, { preserveScroll: true });
                                    }
                                }}
                            >
                                Ontkoppelen
                            </Button>
                        </div>
                    )}

                    <div className="flex gap-1 rounded-full bg-muted p-1">
                        {TABS.map((t) => (
                            <button
                                key={t.key}
                                type="button"
                                onClick={() => setTab(t.key)}
                                className={cn(
                                    'flex-1 rounded-full px-3 py-2 text-xs2 font-semibold transition-colors',
                                    tab === t.key ? 'bg-card text-foreground shadow-btn' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>

                    {!payload ? (
                        <p className="py-8 text-center text-xs2 text-muted-foreground">Bezig met laden…</p>
                    ) : (
                        <>
                            <p className="text-xs2 leading-relaxed text-muted-foreground">{TAB_HINTS[tab](app)}</p>
                            {tab === 'bookings' && <BookingsTab bookings={payload.bookings} />}
                            {tab === 'relations' && (
                                <RelationsTab
                                    payload={payload}
                                    onRelationsChange={(relations) => setPayload({ ...payload, relations })}
                                />
                            )}
                            {tab === 'settings' && (
                                <SettingsTab payload={payload} onSaved={(settings) => setPayload({ ...payload, settings })} />
                            )}
                            <p className="text-xs text-muted-foreground">
                                Deze pagina is persoonlijk en verloopt na 15 minuten.
                            </p>
                        </>
                    )}
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
