import { ProviderLogo } from '@/components/marketing/provider-logo';
import type { ProviderSummary } from '@/types';

export function LogoCloud({ providers, label = 'Koppelt met' }: { providers: ProviderSummary[]; label?: string }) {
    return (
        <div className="flex flex-col items-center gap-6">
            <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">{label}</p>
            <div className="flex flex-wrap items-center justify-center gap-x-10 gap-y-6">
                {providers.map((provider) => (
                    <div key={provider.key} className="flex items-center gap-3 grayscale transition hover:grayscale-0">
                        <ProviderLogo provider={provider} size="sm" />
                        <span className="text-lg font-semibold text-foreground/80">{provider.label}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
