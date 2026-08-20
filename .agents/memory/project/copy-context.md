# Copy-context — Emeq Hub

Read this before writing any customer-facing copy for Emeq Hub. It answers the
intake that `/ai:copywriter` runs, so those questions can be skipped. Gaps at
the bottom are gaps on purpose: do not fill them from imagination.

## ICP

The commercially responsible person at a Dutch software vendor who watches
deals stall on "does it connect to our package?". The dev lead is the second
reader, not the buyer. Confirmed by the owner, 2026-08-19.

The landing page funnel matches this: "Plan je persoonlijke demo" and "Start je
integratie-aanvraag" are commercial entry points, not developer ones. A
developer arriving cold has no way to read the API first.

## Category

Unified API / integration platform. Compared against Merge, Apideck, Nango and
Paragon, and above all against "we'll build it ourselves".

## Story (founder, first person, true)

After three of his own projects needed the same integrations, the owner built
them once properly instead of a fourth time. Evidence: `vendor/emeq` ships in
emeq-hub, sms-opleidingscentrum and planny.

Pain statements derived from artefacts, source = the owner, n=1. These are
founder voice and may be published as such. They are not customer quotes.

- "I built the same connector for the third time and realised I was not
  building it, I was copying it."
- "My first Exact file was not a connector. It was an OAuth tracer. I had to be
  able to see what was going wrong before I could fix it."
  (`ExactOAuthTracerController.php`, first Exact code, 2026-06-16.)
- "23 working days for one connector. And that is one."
- "The integration code sat in eight places before I forced it into one folder
  per provider." (commit `4058de4`.)

The tracer line is the strongest: it concedes the work was hard, which no
competitor in this category will say.

## Numbers (measured, publishable)

- Exact Online connector: 23 working days across 64 calendar days, first code
  2026-06-16, measured over 1040 commits. Caveat: the window runs to
  2026-08-18 and therefore includes extension and maintenance, so it overstates
  "until it worked" rather than understating it.
- Integration code was spread across eight locations until commit `4058de4`
  consolidated it into one folder per provider.

## Partner status — do not overclaim

| Partner | Partnership | Code in repo |
| ------- | ----------- | ------------ |
| Exact Online | yes | 37 files |
| Mollie | yes | 53 files |
| Snelstart | in progress | 8 files |
| Moneybird | yes | none |
| Ibanity / Ponto | yes | none |
| Kadaster (KLIC) | in progress | none |
| CRM | no | none |
| E-commerce | no | none |

The last two rows appear on the live landing page as integration categories.
They have neither a partnership nor a line of code behind them.

## Corrections the live site needs

Checked against the components on 2026-08-20, not against extracted strings.

- "ISO 27001-hosting" is the hosting provider's certification, not Emeq Hub's.
  Sitting between "GDPR" and "Tokens encrypted at rest" it reads as ours. Say
  whose it is. Confirmed by the owner.
- The integrations teaser marks live categories with a green dot and full text
  colour, and everything else stays muted. The dot is `aria-hidden`, so the
  distinction is carried by colour alone. A screen reader announces
  "Boekhouden, Betalen, CRM, E-commerce, + meer" as one flat list with no way
  to tell which one works today. Give the non-live items a text marker.
- `Betalen` is `live: false` in `integrations-teaser.tsx` and that is correct.
  Payments are not live in the Hub yet, confirmed by the owner on 2026-08-20.
  The code reads otherwise at a glance: `config/hub-providers.php` registers
  mollie, `FeatureServiceProvider` defines every registered provider's flag as
  enabled by default, and `routes/api.php` exposes 19 Mollie route groups. That
  is plumbing in place, not a partner a customer can use. Do not let the file
  count or the route count talk you into a payments claim.

The teaser is honest about coverage. Its heading says "Begin met je
boekhouding; de rest van je stack volgt via dezelfde API", and only
Boekhouden carries the live marker. An earlier version of this file claimed
the page overclaimed a partner network. That was wrong, and it came from
reading extracted label strings instead of the component.

## Language

Dutch. Load `copy-nl.mini.md` for the Dutch AI-tells; the 33 humanizer
patterns are English-derived and their word lists do not transfer.

Register is not settled here yet. The current site uses "je" throughout.

## Gaps — do not fill without a source

- **Voice sample.** Missing. Two or three paragraphs of the owner's own Dutch
  writing. Until it exists, humanised output will be generically clean rather
  than in his rhythm.
- **Customer words.** Missing, deliberately deferred. Ask three customers who
  just signed or just walked: what happened right before you looked for us;
  what had you already tried; how do you describe us to a colleague; what
  nearly made you walk. Record verbatim, never paraphrased.
- **Time to first successful call.** Measured on production 2026-08-20 and
  **not publishable**. One connection exists, for exact, with 4.4 days between
  the connection and its first 2xx. That gap is the owner's own working rhythm,
  not a customer onboarding time, and a median over one row is not a median.
  The connection itself is active: 248 pass-through calls sit behind it.

  Re-run when at least five real customer connections exist. Until then the
  copy carries no onboarding-speed claim. The query, unchanged, is below. The
  `pass_through_calls` table records `connection_id`, the upstream `status`,
  and `created_at`, so the span from connection to first 2xx is one query
  (PostgreSQL):

  ```sql
  SELECT c.provider,
         count(*)                                   AS connections,
         percentile_cont(0.5) WITHIN GROUP (
           ORDER BY f.first_ok - c.created_at)       AS median_time_to_first_call,
         min(f.first_ok - c.created_at)              AS fastest
  FROM connections c
  JOIN LATERAL (
    SELECT min(p.created_at) AS first_ok
    FROM pass_through_calls p
    WHERE p.connection_id = c.id
      AND p.status BETWEEN 200 AND 299
  ) f ON f.first_ok IS NOT NULL
  GROUP BY c.provider;
  ```

  Report the median, not the mean: one abandoned connection drags a mean
  anywhere. Say how many connections the number covers, because a median over
  three is not a claim.
