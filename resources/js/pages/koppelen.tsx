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
                        <span className="text-brand">groeien.</span>
                    </>
                }
                intro="Vertel ons welke software je wilt koppelen. We denken mee over de slimste route en zorgen dat je snel kunt starten."
                steps={intakeSteps}
            >
                <KoppelForm providers={providers.map(({ key, label }) => ({ key, label }))} />
            </IntakeShell>
        </MotionConfig>
    );
}
