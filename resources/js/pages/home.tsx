import { MotionConfig } from 'framer-motion';
import { CodeSplit } from '@/components/landing/code-split';
import { FeatureBento } from '@/components/landing/feature-bento';
import { FinalCta } from '@/components/landing/final-cta';
import { Footer } from '@/components/landing/footer';
import { Hero } from '@/components/landing/hero';
import { HowItWorks } from '@/components/landing/how-it-works';
import { IntegrationsTeaser } from '@/components/landing/integrations-teaser';
import { Nav } from '@/components/landing/nav';
import { PartnerStrip } from '@/components/landing/partner-strip';
import { SecurityBand } from '@/components/landing/security-band';
import { ValueTrio } from '@/components/landing/value-trio';
import { Seo } from '@/components/seo';
import { type ProviderSummary, type SeoMeta } from '@/lib/types';

interface HomeProps {
    providers: ProviderSummary[];
    seo: SeoMeta;
}

export default function Home({ providers, seo }: HomeProps) {
    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <Nav />
            <main>
                <Hero />
                <PartnerStrip providers={providers} />
                <ValueTrio />
                <HowItWorks />
                <CodeSplit />
                <IntegrationsTeaser />
                <SecurityBand />
                <FeatureBento />
                <FinalCta />
            </main>
            <Footer />
        </MotionConfig>
    );
}
