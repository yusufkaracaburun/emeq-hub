{{-- Plan 08-05 — Domeinmodel-uitleg-blokje (D-06, UI-SPEC §S3 regel 182-186). --}}
{{-- Canonical copy — paraphrase NIET zonder UI-SPEC-revisie. --}}
<section class="rounded-lg bg-gray-50 dark:bg-neutral-900 p-6 my-12">
    <h2 class="text-xl font-semibold leading-tight mb-4">Hoe de Hub-tenancy in elkaar zit</h2>
    <ul class="space-y-2 text-base">
        <li><strong>Consumer</strong> &rarr; jouw SaaS-app (Naschool, Planny, externe app). Authenticeert met een Bearer-PAT.</li>
        <li><strong>Account</strong> &rarr; een klant van jouw app (school A, vereniging C).</li>
        <li><strong>Connection</strong> &rarr; de partner-koppeling van die Account (school A's eigen Mollie- of Snelstart-account).</li>
    </ul>
</section>
