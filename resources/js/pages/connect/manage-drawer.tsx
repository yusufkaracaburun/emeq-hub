import { router, useHttp } from '@inertiajs/react';
import { Dialog } from 'radix-ui';
import { useEffect, useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { RefreshGlyph, UnlinkGlyph } from '@/components/ui/glyphs';
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
        mode: 'view' | 'manage';
        journals: { sales: string | null; purchase: string | null; options: RefOption[] };
        gl_accounts: { invoice: string | null; self_billing: string | null; suspense: string | null; options: RefOption[] };
        vat_codes: { key: '21' | '9' | '0' | 'reverse_charge:21' | 'reverse_charge:9'; label: string; value: string | null }[];
        vat_options: RefOption[];
        categories: {
            key: string;
            label: string;
            type: 'expense' | 'income' | null;
            gl_account: string | null;
            suggestion: string | null;
            orphaned: boolean;
        }[];
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

const TAB_HINTS: Record<Tab, (app: string, providerLabel: string) => string> = {
    bookings: (app) => `Wat ${app} de afgelopen dagen naar je administratie heeft gestuurd.`,
    relations: (app, providerLabel) => `Zo weet ${providerLabel} welke klant of leverancier uit ${app} bij welke relatie hoort.`,
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
    name: 'NAAM · CONTROLEER',
    created: 'NIEUW AANGEMAAKT',
    pinned: 'HANDMATIG GEKOPPELD',
};

const CONNECTION_STATUS_LABELS: Record<string, string> = {
    active: 'ACTIEF',
    pending: 'BEZIG',
    needs_consent: 'TOESTEMMING NODIG',
};

function Pill({ className, children }: { className?: string; children: React.ReactNode }) {
    return (
        <span className={cn('whitespace-nowrap rounded-full px-2 py-[2px] font-caption text-2xs uppercase tracking-[1px]', className)}>
            {children}
        </span>
    );
}

function MatchedOnPill({ matchedOn }: { matchedOn: MatchedOn | null }) {
    if (matchedOn === null) {
        return null;
    }

    return (
        <Pill
            className={cn(
                matchedOn === 'name' && 'bg-warning-soft text-warning-deep',
                matchedOn === 'created' && 'bg-brand-soft text-brand-deep',
                (matchedOn === 'kvk' || matchedOn === 'vat' || matchedOn === 'pinned') && 'bg-muted text-muted-foreground',
            )}
        >
            {MATCHED_ON_LABELS[matchedOn]}
        </Pill>
    );
}

function StatusPill({ posted }: { posted: boolean }) {
    return <Pill className={posted ? 'bg-success-soft text-success' : 'bg-error-soft text-error-deep'}>{posted ? 'GEBOEKT' : 'GEWEIGERD'}</Pill>;
}

function ColumnHeader({ className, children }: { className?: string; children?: React.ReactNode }) {
    return <span className={cn('font-caption text-2xs uppercase tracking-[1px] text-muted-foreground', className)}>{children}</span>;
}

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' });
}

function formatShortDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' });
}

function EmptyState({ children }: { children: React.ReactNode }) {
    return <p className="rounded-lg bg-muted px-5 py-8 text-center text-xs2 text-muted-foreground">{children}</p>;
}

/**
 * Boekingen krijgt bewust geen data: `pass_through_calls.response_body` (waar de
 * melding — inclusief `warnings[]` — in zou staan) wordt alleen gevuld bij een
 * fout (status >= 400). Voor een geslaagde boeking is er dus geen melding om te
 * tonen. Zie het sessierapport voor de volledige onderbouwing.
 */
function BookingsTab({ bookings }: { bookings: BookingRow[] }) {
    if (bookings.length === 0) {
        return <EmptyState>Nog niets geboekt. Zodra er een factuur of bon naar je administratie gaat, staat 'ie hier.</EmptyState>;
    }

    return (
        <div className="overflow-hidden rounded-lg border border-border">
            {/* Eigen scrollgebied met sticky kolomkoppen — er kunnen honderden boekingen zijn. */}
            <div className="max-h-[438px] overflow-y-auto overscroll-contain">
                <div className="sticky top-0 z-10 flex gap-2 border-b border-border bg-muted px-3.5 py-2">
                    <ColumnHeader className="w-[84px] shrink-0">Datum</ColumnHeader>
                    <ColumnHeader className="w-[188px] shrink-0">Document</ColumnHeader>
                    <ColumnHeader className="w-[128px] shrink-0">Status</ColumnHeader>
                    <ColumnHeader className="flex-1">Melding</ColumnHeader>
                </div>
                {bookings.map((booking, index) => (
                    <div
                        key={`${booking.booked_at}-${index}`}
                        className="flex items-center gap-2 border-b border-border px-3.5 py-2.5 last:border-b-0"
                    >
                        <span className="w-[84px] shrink-0 text-xs2 text-muted-foreground">{formatShortDate(booking.booked_at)}</span>
                        <span className={cn('w-[188px] shrink-0 truncate text-xs2', booking.document ? 'font-semibold text-foreground' : 'text-muted-foreground')}>
                            {booking.document ?? '—'}
                        </span>
                        <span className="w-[128px] shrink-0">
                            <StatusPill posted={booking.posted} />
                        </span>
                        <span className="min-w-0 flex-1 text-xs2 leading-relaxed text-muted-foreground">
                            {booking.messages.length === 0 ? '—' : booking.messages.join(' · ')}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function RelationRowView({
    app,
    relation,
    searchUrl,
    onChanged,
}: {
    app: string;
    relation: RelationRow;
    searchUrl: string;
    onChanged: (updated: RelationRow | null, id: number) => void;
}) {
    const [editing, setEditing] = useState(false);
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
                setEditing(false);
            },
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pendingPick]);

    const pick = (result: SearchResult) => {
        relink.setData({ native_id: result.id, label: result.name });
        setPendingPick(true);
    };

    const removeLink = () => {
        if (window.confirm(`De koppeling met '${relation.label ?? relation.code}' loslaten? Bij de volgende boeking zoekt ${app} opnieuw.`)) {
            unlink.delete(relation.unlink_url, {
                onSuccess: () => onChanged(null, relation.id),
            });
        }
    };

    return (
        <div className="border-b border-border last:border-b-0">
            <div className="flex items-center gap-2 px-3.5 py-2.5">
                <span className="w-[180px] shrink-0 truncate font-data text-xs2 font-semibold text-foreground">{relation.code}</span>
                <span className={cn('w-[200px] shrink-0 truncate text-xs2', relation.label ? 'text-foreground' : 'text-muted-foreground')}>
                    {relation.label ?? '—'}
                </span>
                <span className="w-[150px] shrink-0">
                    <MatchedOnPill matchedOn={relation.matched_on} />
                </span>
                <span className="flex flex-1 justify-end">
                    <button
                        type="button"
                        onClick={() => setEditing((v) => !v)}
                        className={cn(
                            'rounded-[6px] border border-border bg-card px-2.5 py-1.5 text-xs font-semibold text-foreground',
                            'transition-colors hover:border-brand hover:bg-muted',
                        )}
                    >
                        {editing ? 'Sluiten' : 'Wijzigen'}
                    </button>
                </span>
            </div>

            {editing && (
                <div className="flex flex-col gap-2 border-t border-border bg-muted/40 px-3.5 py-3">
                    <p className="text-xs2 text-muted-foreground">
                        Koppel aan een andere relatie: typ de volledige bedrijfsnaam zoals die in de administratie staat.
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
                            className="flex items-center justify-between rounded-md border border-border bg-card px-3 py-2 text-left text-xs2 hover:border-brand hover:bg-muted"
                        >
                            <span>{result.name}</span>
                            <span className="text-muted-foreground">Koppel →</span>
                        </button>
                    ))}

                    <button
                        type="button"
                        onClick={removeLink}
                        disabled={unlink.processing}
                        className="self-start text-xs2 font-semibold text-muted-foreground underline-offset-2 transition-colors hover:text-error-deep hover:underline"
                    >
                        {unlink.processing ? 'Bezig…' : 'Of: deze koppeling loslaten'}
                    </button>
                </div>
            )}
        </div>
    );
}

function RelationsTab({
    app,
    payload,
    onRelationsChange,
}: {
    app: string;
    payload: ManagePayload;
    onRelationsChange: (relations: RelationRow[]) => void;
}) {
    if (payload.relations.length === 0) {
        return <EmptyState>Nog geen relaties gekoppeld. Ze verschijnen hier zodra een boeking er één herkent of aanmaakt.</EmptyState>;
    }

    return (
        <div className="overflow-hidden rounded-lg border border-border">
            <div className="max-h-[438px] overflow-y-auto overscroll-contain">
                <div className="sticky top-0 z-10 flex gap-2 border-b border-border bg-muted px-3.5 py-2">
                    <ColumnHeader className="w-[180px] shrink-0">In {app}</ColumnHeader>
                    <ColumnHeader className="w-[200px] shrink-0">In {payload.connection.label}</ColumnHeader>
                    <ColumnHeader className="w-[150px] shrink-0">Herkend op</ColumnHeader>
                    <ColumnHeader className="flex-1" />
                </div>
                {payload.relations.map((relation) => (
                    <RelationRowView
                        key={relation.id}
                        app={app}
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
        </div>
    );
}

type Settings = ManagePayload['settings'];
type CategoryRow = Settings['categories'][number];

interface MappingForm {
    journals: { sales: string | null; purchase: string | null };
    gl_accounts: Record<string, string | null>;
    vat_codes: Record<string, string | null>;
}

const CATEGORY_TYPE_LABELS: Record<'expense' | 'income', string> = {
    expense: 'Kosten',
    income: 'Omzet',
};

function mappingForm(settings: Settings): MappingForm {
    return {
        journals: { sales: settings.journals.sales, purchase: settings.journals.purchase },
        gl_accounts: {
            invoice: settings.gl_accounts.invoice,
            self_billing: settings.gl_accounts.self_billing,
            suspense: settings.gl_accounts.suspense,
            ...Object.fromEntries(settings.categories.map((category) => [category.key, category.gl_account])),
        },
        vat_codes: Object.fromEntries(settings.vat_codes.map((row) => [row.key, row.value])),
    };
}

function SettingsSection({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
                <h3 className="text-sm font-semibold text-foreground">{title}</h3>
                {description && <p className="text-xs2 leading-relaxed text-muted-foreground">{description}</p>}
            </div>
            {children}
        </section>
    );
}

function FieldError({ id, message }: { id: string; message: string | undefined }) {
    return message ? (
        <span id={id} className="text-xs text-error-deep">
            {message}
        </span>
    ) : null;
}

function MappingField({
    label,
    value,
    options,
    error,
    readOnly,
    onChange,
}: {
    label: string;
    value: string | null;
    options: RefOption[];
    error: string | undefined;
    readOnly: boolean;
    onChange: (code: string | null) => void;
}) {
    const id = useId();

    return (
        <div className="flex min-w-0 flex-col gap-1">
            <span id={`${id}-label`} className="text-xs2 text-muted-foreground">
                {label}
            </span>
            <Combobox
                value={value}
                options={options}
                onChange={onChange}
                disabled={readOnly}
                invalid={error !== undefined}
                labelledBy={`${id}-label`}
                describedBy={error ? `${id}-error` : undefined}
            />
            <FieldError id={`${id}-error`} message={error} />
        </div>
    );
}

function CategoryRowView({
    category,
    value,
    options,
    error,
    readOnly,
    onChange,
}: {
    category: CategoryRow;
    value: string | null;
    options: RefOption[];
    error: string | undefined;
    readOnly: boolean;
    onChange: (code: string | null) => void;
}) {
    const id = useId();
    const suggestion =
        category.suggestion !== null && category.gl_account === null && value === null
            ? (options.find((option) => option.code === category.suggestion) ?? { code: category.suggestion, label: category.suggestion })
            : null;

    return (
        <div className="flex flex-col gap-2 border-b border-border px-3.5 py-3 last:border-b-0 sm:flex-row sm:items-start sm:gap-4">
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <span id={`${id}-label`} className="text-xs2 font-semibold text-foreground">
                    {category.label}
                </span>
                <div className="flex flex-wrap items-center gap-1.5">
                    {category.type && <span className="text-2xs text-muted-foreground">{CATEGORY_TYPE_LABELS[category.type]}</span>}
                    {category.orphaned ? (
                        <Pill className="bg-muted text-muted-foreground">Niet meer in je categorielijst</Pill>
                    ) : (
                        category.gl_account === null && <Pill className="bg-warning-soft text-warning-deep">Nog niet gekoppeld</Pill>
                    )}
                </div>
            </div>
            <div className="flex w-full flex-col gap-1 sm:w-[300px] sm:shrink-0">
                <Combobox
                    value={value}
                    options={options}
                    onChange={onChange}
                    disabled={readOnly}
                    invalid={error !== undefined}
                    labelledBy={`${id}-label`}
                    describedBy={error ? `${id}-error` : undefined}
                />
                <FieldError id={`${id}-error`} message={error} />
                {suggestion && !readOnly && (
                    <button
                        type="button"
                        onClick={() => onChange(suggestion.code)}
                        className="self-start truncate text-left text-xs2 font-semibold text-brand-deep underline-offset-2 transition-colors hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    >
                        Voorstel: <span className="font-data">{suggestion.label}</span>
                    </button>
                )}
            </div>
        </div>
    );
}

function SettingsTab({ app, payload, onSaved }: { app: string; payload: ManagePayload; onSaved: (settings: Settings) => void }) {
    const settings = payload.settings;
    const readOnly = settings.mode === 'view';
    const mapping = useHttp<MappingForm, { settings: Settings }>(mappingForm(settings));
    const [saved, setSaved] = useState(false);
    const [failed, setFailed] = useState(false);
    const errors = mapping.errors as Record<string, string | undefined>;
    const errorCount = Object.keys(errors).length;
    const glOptions = settings.gl_accounts.options;

    const update = (section: keyof MappingForm, key: string, code: string | null) => {
        mapping.setData((previous) => ({ ...previous, [section]: { ...previous[section], [key]: code } }));
        setSaved(false);
    };

    const save = () => {
        setSaved(false);
        setFailed(false);
        mapping.put(payload.urls.mapping_url, {
            onSuccess: (data) => {
                onSaved(data.settings);
                setSaved(true);
            },
            onHttpException: () => {
                setFailed(true);
            },
            onNetworkError: () => {
                setFailed(true);
            },
            // The callbacks above already report every failure; the rejected promise would only surface as an unhandled error.
        }).catch(() => undefined);
    };

    return (
        <div className="flex flex-col gap-7">
            {readOnly && (
                <p className="rounded-lg bg-muted px-4 py-3 text-xs2 text-muted-foreground">
                    <span className="font-semibold text-foreground">Alleen-lezen.</span> Je kunt deze instellingen bekijken maar niet wijzigen.
                </p>
            )}

            <SettingsSection title="Basis">
                <div className="grid gap-3 sm:grid-cols-2">
                    <MappingField
                        label="Dagboek verkoopfacturen"
                        value={mapping.data.journals.sales}
                        options={settings.journals.options}
                        error={errors['journals.sales']}
                        readOnly={readOnly}
                        onChange={(code) => update('journals', 'sales', code)}
                    />
                    <MappingField
                        label="Dagboek inkoopfacturen"
                        value={mapping.data.journals.purchase}
                        options={settings.journals.options}
                        error={errors['journals.purchase']}
                        readOnly={readOnly}
                        onChange={(code) => update('journals', 'purchase', code)}
                    />
                    <MappingField
                        label="Grootboek verkoopfacturen"
                        value={mapping.data.gl_accounts.invoice ?? null}
                        options={glOptions}
                        error={errors['gl_accounts.invoice']}
                        readOnly={readOnly}
                        onChange={(code) => update('gl_accounts', 'invoice', code)}
                    />
                    <MappingField
                        label="Grootboek self-billing"
                        value={mapping.data.gl_accounts.self_billing ?? null}
                        options={glOptions}
                        error={errors['gl_accounts.self_billing']}
                        readOnly={readOnly}
                        onChange={(code) => update('gl_accounts', 'self_billing', code)}
                    />
                </div>
            </SettingsSection>

            <SettingsSection title="Btw" description={`Welke btw-code uit ${payload.connection.label} hoort bij elk tarief.`}>
                <div className="grid gap-3 sm:grid-cols-2">
                    {settings.vat_codes.map((row) => (
                        <MappingField
                            key={row.key}
                            label={row.label}
                            value={mapping.data.vat_codes[row.key] ?? null}
                            options={settings.vat_options}
                            error={errors[`vat_codes.${row.key}`]}
                            readOnly={readOnly}
                            onChange={(code) => update('vat_codes', row.key, code)}
                        />
                    ))}
                </div>
            </SettingsSection>

            <SettingsSection
                title="Categorieën"
                description={`Op welke grootboekrekening elke categorie uit ${app} geboekt wordt.`}
            >
                {settings.categories.length === 0 ? (
                    <EmptyState>Nog geen categorieën ontvangen. Die komen mee zodra je app de koppelpagina opent.</EmptyState>
                ) : (
                    <div className="rounded-lg border border-border">
                        {settings.categories.map((category) => (
                            <CategoryRowView
                                key={category.key}
                                category={category}
                                value={mapping.data.gl_accounts[category.key] ?? null}
                                options={glOptions}
                                error={errors[`gl_accounts.${category.key}`]}
                                readOnly={readOnly}
                                onChange={(code) => update('gl_accounts', category.key, code)}
                            />
                        ))}
                    </div>
                )}
            </SettingsSection>

            <SettingsSection
                title="Tussenrekening"
                description="Optioneel. Met een tussenrekening boekt een categorie zonder koppeling op die rekening. Zonder tussenrekening wacht het document tot de categorie gekoppeld is."
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <MappingField
                        label="Tussenrekening"
                        value={mapping.data.gl_accounts.suspense ?? null}
                        options={glOptions}
                        error={errors['gl_accounts.suspense']}
                        readOnly={readOnly}
                        onChange={(code) => update('gl_accounts', 'suspense', code)}
                    />
                </div>
            </SettingsSection>

            {!readOnly && (
                <div className="flex flex-col gap-2 border-t border-border pt-5">
                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="button" size="sm" onClick={save} disabled={mapping.processing}>
                            {mapping.processing ? 'Bezig…' : 'Bevestigen'}
                        </Button>
                        {saved && (
                            <span role="status" className="text-xs2 text-success">
                                Opgeslagen. Documenten die wachtten, kun je nu opnieuw boeken vanuit {app}.
                            </span>
                        )}
                    </div>
                    {errorCount > 0 && (
                        <p role="alert" className="text-xs2 text-error-deep">
                            Niets opgeslagen. {errorCount === 1 ? 'Eén veld klopt' : `${errorCount} velden kloppen`} niet, zie hierboven.
                            {errors.gl_accounts && ` ${errors.gl_accounts}`}
                        </p>
                    )}
                    {failed && (
                        <p role="alert" className="text-xs2 text-error-deep">
                            Opslaan is niet gelukt. Probeer het opnieuw.
                        </p>
                    )}
                </div>
            )}
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
    provider: { key: string; label: string; logo: string | null; manage_url: string | null } | null;
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

    const lastExchange = payload?.bookings[0]?.booked_at ?? null;

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-40 bg-overlay" />
                <Dialog.Content
                    className={cn(
                        'fixed z-50 flex flex-col gap-6 overflow-y-auto bg-card p-6 shadow-card',
                        'inset-x-0 bottom-0 max-h-[85vh] rounded-t-2xl',
                        'sm:inset-y-0 sm:right-0 sm:left-auto sm:max-h-none sm:w-full sm:max-w-[760px] sm:rounded-none sm:border-l sm:border-border sm:px-8 sm:py-7',
                    )}
                >
                    <div className="flex items-center justify-between gap-3">
                        <Dialog.Close asChild>
                            <button
                                type="button"
                                className="flex items-center gap-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <span aria-hidden>←</span> <span className="font-semibold">Je koppelingen</span>
                            </button>
                        </Dialog.Close>
                        <Dialog.Close asChild>
                            <button
                                type="button"
                                aria-label="Sluiten"
                                className="flex size-8 items-center justify-center rounded-md bg-muted text-sm text-muted-foreground transition-colors hover:text-foreground"
                            >
                                ✕
                            </button>
                        </Dialog.Close>
                    </div>

                    <div className="flex flex-col gap-2">
                        <span className="font-caption text-xs uppercase tracking-[2px] text-brand">Koppeling beheren</span>
                        <Dialog.Title className="text-2xl font-bold leading-[1.15] text-foreground">
                            {payload?.connection.label ?? provider?.label}
                        </Dialog.Title>
                        <Dialog.Description className="text-sm leading-relaxed text-muted-foreground">
                            Hieronder zie je wat {app} met je administratie uitwisselt. Klopt er iets niet, dan herstel je
                            het hier.
                        </Dialog.Description>
                    </div>

                    {!payload ? (
                        <p className="py-8 text-center text-xs2 text-muted-foreground">Bezig met laden…</p>
                    ) : (
                        <>
                            <div className="flex items-center gap-4 rounded-lg border border-border bg-card px-[22px] py-[18px]">
                                {provider?.logo && (
                                    <div className="flex w-[84px] shrink-0 items-center">
                                        <img src={provider.logo} alt="" aria-hidden className="h-[21px] w-auto" />
                                    </div>
                                )}
                                <div className="flex min-w-0 flex-1 flex-col gap-[3px]">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-semibold text-foreground">
                                            {payload.connection.administratie_id === null
                                                ? payload.connection.label
                                                : `Administratie ${payload.connection.administratie_id}`}
                                        </span>
                                        <Pill className="bg-success-soft text-success">
                                            {CONNECTION_STATUS_LABELS[payload.connection.status] ?? payload.connection.status}
                                        </Pill>
                                    </div>
                                    <span className="text-xs2 leading-relaxed text-muted-foreground">
                                        {app} · gekoppeld sinds {formatDate(payload.connection.connected_since)}
                                        {lastExchange && ` · laatste uitwisseling ${formatShortDate(lastExchange)}`}
                                    </span>
                                </div>
                                {/*
                                    Zelfde routes als de rij op de picker-pagina: `connect.start` is
                                    een POST (Inertia::location naar de partner-authorize-URL),
                                    `connect.disconnect` een DELETE. `window.location.href` zou hier
                                    een GET afvuren en op beide een 405 opleveren.
                                */}
                                <div className="flex shrink-0 items-center gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        title="Opnieuw koppelen"
                                        className="size-[37px] p-0"
                                        onClick={() => router.post(payload.connection.reconnect_url, {}, { preserveScroll: true })}
                                    >
                                        <RefreshGlyph className="size-4" />
                                        <span className="sr-only">Opnieuw koppelen</span>
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        title="Ontkoppelen"
                                        className="size-[37px] p-0"
                                        onClick={() => {
                                            if (window.confirm(`${payload.connection.label} ontkoppelen? De toegang wordt ingetrokken.`)) {
                                                router.delete(payload.connection.disconnect_url, { preserveScroll: true });
                                            }
                                        }}
                                    >
                                        <UnlinkGlyph className="size-4" />
                                        <span className="sr-only">Ontkoppelen</span>
                                    </Button>
                                </div>
                            </div>

                            <div className="flex flex-col gap-3">
                                <div className="flex flex-wrap gap-1 self-start rounded-md bg-muted p-1">
                                    {TABS.map((t) => (
                                        <button
                                            key={t.key}
                                            type="button"
                                            onClick={() => setTab(t.key)}
                                            className={cn(
                                                'rounded-[6px] px-3.5 py-2 text-sm transition-colors',
                                                tab === t.key
                                                    ? 'border border-border bg-card font-semibold text-foreground'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {t.label}
                                        </button>
                                    ))}
                                </div>

                                <p className="text-xs2 leading-relaxed text-muted-foreground">{TAB_HINTS[tab](app, payload.connection.label)}</p>
                                {tab === 'bookings' && <BookingsTab bookings={payload.bookings} />}
                                {tab === 'relations' && (
                                    <RelationsTab
                                        app={app}
                                        payload={payload}
                                        onRelationsChange={(relations) => setPayload({ ...payload, relations })}
                                    />
                                )}
                                {tab === 'settings' && (
                                    <SettingsTab app={app} payload={payload} onSaved={(settings) => setPayload({ ...payload, settings })} />
                                )}
                            </div>

                            <p className="text-xs text-muted-foreground">Deze pagina is persoonlijk en verloopt na 15 minuten.</p>
                        </>
                    )}
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
