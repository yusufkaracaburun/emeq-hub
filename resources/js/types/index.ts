export interface ProviderSummary {
    key: string;
    label: string;
    tagline: string;
    category: string;
    summary: string;
    logo: string | null;
    brand: string | null;
}

export interface Capability {
    title: string;
    description: string;
}

export interface EndpointRef {
    method: string;
    path: string;
    target: string;
    description: string;
}

export interface UseCase {
    title: string;
    value: string;
}

export interface IntegrationStep {
    title: string;
    description: string;
}

export interface ProviderDetail extends ProviderSummary {
    how_it_works?: string[];
    use_cases?: UseCase[];
    capabilities: Capability[];
    endpoints?: EndpointRef[];
    integration?: IntegrationStep[];
    connect_steps: string[];
    example_curl: string | null;
    docs_url: string | null;
}

export interface SharedProps {
    appName: string;
    [key: string]: unknown;
}
