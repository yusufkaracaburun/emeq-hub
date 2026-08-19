import { MotionConfig } from 'framer-motion';
import { IntakeShell } from '@/components/landing/intake-shell';
import { intakeSteps } from '@/components/landing/intake-steps';
import { KoppelForm } from '@/components/landing/koppel-form';
import { Seo } from '@/components/seo';
import { type ProviderSummary, type SeoMeta } from '@/lib/types';

interface KoppelenProps {
    providers: ProviderSummary[];
    seo: SeoMeta;
}

export default function Koppelen({ providers, seo }: KoppelenProps) {
    return (
        <MotionConfig reducedMotion="user">
            <Seo seo={seo} />
            <IntakeShell
                eyebrow="Aan de slag"
                title={
                    <>
                        Start met <br />
                        <span className="text-brand">koppelen.</span>
                    </>
                }
                intro="Vertel welke software je wilt koppelen. Wij kijken mee welke route het snelst werkt."
                steps={intakeSteps}
            >
                <KoppelForm providers={providers.map(({ key, label }) => ({ key, label }))} />
            </IntakeShell>
        </MotionConfig>
    );
}
