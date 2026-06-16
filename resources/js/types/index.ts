export interface ProviderSummary {
    key: string;
    label: string;
    tagline: string;
    category: string;
    summary: string;
}

export interface Capability {
    title: string;
    description: string;
}

export interface ProviderDetail extends ProviderSummary {
    capabilities: Capability[];
    connect_steps: string[];
    example_curl: string | null;
    docs_url: string | null;
}

export interface SharedProps {
    appName: string;
    [key: string]: unknown;
}
