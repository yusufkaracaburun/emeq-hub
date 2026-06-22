<x-filament-panels::page>
    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <article class="emeq-doc">
            {!! $this->guideHtml() !!}
        </article>
    </div>

    {{-- Geen @tailwindcss/typography in de stack (Tailwind v4); scoped-style i.p.v. een plugin-dependency. --}}
    <style>
        .emeq-doc { line-height: 1.6; font-size: 0.925rem; }
        .emeq-doc h1 { font-size: 1.6rem; font-weight: 700; margin: 1.2em 0 .6em; }
        .emeq-doc h2 { font-size: 1.3rem; font-weight: 700; margin: 1.4em 0 .5em; padding-bottom: .25em; border-bottom: 1px solid rgba(127,127,127,.25); }
        .emeq-doc h3 { font-size: 1.1rem; font-weight: 600; margin: 1.2em 0 .4em; }
        .emeq-doc h4 { font-weight: 600; margin: 1em 0 .3em; }
        .emeq-doc p, .emeq-doc ul, .emeq-doc ol { margin: .6em 0; }
        .emeq-doc ul, .emeq-doc ol { padding-left: 1.4em; }
        .emeq-doc ul { list-style: disc; }
        .emeq-doc ol { list-style: decimal; }
        .emeq-doc li { margin: .2em 0; }
        .emeq-doc a { color: #2563eb; text-decoration: underline; }
        .dark .emeq-doc a { color: #60a5fa; }
        .emeq-doc code { background: rgba(127,127,127,.16); padding: .12em .35em; border-radius: .25rem; font-size: .85em; }
        .emeq-doc pre { background: rgba(127,127,127,.14); padding: 1em; border-radius: .5rem; overflow-x: auto; margin: .8em 0; }
        .emeq-doc pre code { background: none; padding: 0; font-size: .82em; }
        .emeq-doc table { width: 100%; border-collapse: collapse; margin: .8em 0; font-size: .85em; }
        .emeq-doc th, .emeq-doc td { border: 1px solid rgba(127,127,127,.28); padding: .4em .6em; text-align: left; vertical-align: top; }
        .emeq-doc th { background: rgba(127,127,127,.12); font-weight: 600; }
        .emeq-doc blockquote { border-left: 3px solid rgba(127,127,127,.4); padding-left: 1em; margin: .8em 0; opacity: .85; }
        .emeq-doc hr { border: none; border-top: 1px solid rgba(127,127,127,.25); margin: 1.4em 0; }
    </style>
</x-filament-panels::page>
