import { cn } from '@/lib/utils';

export interface IntakeStep {
    title: string;
    description: string;
}

/** De drie intake-stappen van /koppelen — ook in de Koppelen-sectie op de partner-detailpagina. */
export const intakeSteps: IntakeStep[] = [
    {
        title: 'We kijken wat je wilt koppelen',
        description: 'Een kort gesprek over welke systemen het zijn en welke data er langs moet.',
    },
    {
        title: 'Je omgeving staat klaar',
        description: 'Je krijgt een eigen omgeving en een API-token, met uitleg erbij.',
    },
    {
        title: 'Je eerste koppeling gaat live',
        description: 'We kijken mee tot hij in productie draait.',
    },
];

/** Genummerde stappenlijst (01/02/03 in mono-brand) — het "Wat er gebeurt"-blok uit de intake-frames. */
export function IntakeStepList({ steps }: { steps: IntakeStep[] }) {
    return (
        <ol className="flex flex-col">
            {steps.map((step, index) => (
                <li
                    key={step.title}
                    className={cn(
                        'flex items-start gap-4 border-b border-border py-[18px]',
                        index === 0 && 'border-t',
                    )}
                >
                    <span className="font-mono text-xs2 text-brand">{`0${index + 1}`}</span>
                    <div className="flex flex-col gap-1">
                        <p className="text-base font-semibold text-foreground">{step.title}</p>
                        <p className="text-sm leading-[1.6] text-muted-foreground">{step.description}</p>
                    </div>
                </li>
            ))}
        </ol>
    );
}
