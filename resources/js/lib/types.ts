export interface ProviderSummary {
    key: string;
    label: string;
    tagline: string;
    category: string;
    summary: string;
    logo: string | null;
    brand: string | null;
    live: boolean;
}

export interface ProviderDetail {
    key: string;
    label: string;
    tagline: string;
    category: string;
    summary: string;
    logo: string | null;
    brand: string | null;
    live?: boolean;
    headline?: string;
    intro?: string;
    features?: { icon: string; title: string; description: string }[];
    steps?: { title: string; description: string }[];
    connect_pitch?: string;
    how_it_works?: string[];
    use_cases?: { title: string; value: string }[];
    capabilities: { title: string; description: string }[];
    endpoints?: { method: string; path: string; target: string; description: string }[];
    integration?: { title: string; description: string }[];
    connect_steps: string[];
    example_curl: string | null;
    docs_url: string | null;
    website_url?: string | null;
    support_url?: string | null;
}

/** Server-side opgebouwd in App\Support\Seo\SeoMeta — niet client-side aanvullen. */
export interface SeoMeta {
    title: string;
    description: string;
    canonical: string;
    type: string;
    image: string;
    locale: string;
    siteName: string;
    jsonLd: Record<string, unknown>;
}

export interface SharedProps {
    appName: string;
    flash: { submitted: boolean };
    [key: string]: unknown;
}
