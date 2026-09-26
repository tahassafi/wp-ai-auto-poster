# WP Auto Poster

A WordPress plugin that publishes one SEO article per day for a WooCommerce shop, using the Anthropic Messages API — built for a client running a specialist auto-parts store.

Each run takes the oldest keyword off a queue, picks **one real, in-stock product in code**, asks the model to write around it, then repairs and validates the result before anything reaches the database. If the article fails a rule, nothing is published and the keyword is flagged with the reason.

Built to replace a paid SaaS auto-poster that offered no control over which products were promoted, how links were formed, or whether the output met the client's editorial rules.

---

## What it actually does

```
                                      ┌───────────────────────────┐
  SEO manager pastes keywords ───────▶│  wp_wpap_keywords         │
  (wp-admin → AI Poster)              │  pending / running /      │
                                      │  used / failed            │
                                      └────────────┬──────────────┘
                                                   │  oldest pending, claimed
                                                   │  via an atomic status flip
  cPanel cron, once daily                          ▼
  php cron-post.php  ──▶  wp-load.php  ──▶  wpap_run_once()
                                                   │
        ┌──────────────────────────────────────────┼──────────────────────────────┐
        │                                          │                              │
        ▼                                          ▼                              ▼
  1. ANCHOR (code)                          2. WRITE (model)              3. GUARD (code)
  ─────────────────                         ────────────────              ───────────────
  score keyword against                     claude-sonnet-5               parse ===SECTIONS===
  every in-stock product:                   max_tokens 8192               sanitise HTML
    brand   x6                              delimiter sections,           repair every <a>
    model   x5                              never JSON                    enforce keyword link
    category x4                                   │                       shorten anchor text
    title word x2                                 │                       swap {{IMG1}} for
    title phrase x3                               │                         the product image
        │                                         │                       validate, or reject
  demote: product already used,                   │                              │
  brand/model used in last N                      │                              ▼
        │                                         │                       4. PUBLISH
  no word match at all?                           │                       ──────────
  claude-haiku-4-5 picks from                     │                       wp_insert_post
  the 60 least-recently-used                      │                       cover image from pool,
        │                                         │                         renamed to the slug
        └─────────────────────────────────────────┘                       SEO title/description
                                                                          IndexNow ping
```

The model never chooses the product, never chooses the image, and never has the final say on a link. Those are code decisions, and the separation is the point of the design.

---

## Request / data flow

1. **Claim.** `wpap_claim_keyword()` selects the oldest `pending` row and flips it to `running` in a conditional `UPDATE`. Two overlapping cron runs cannot claim the same keyword — the second update matches zero rows and the run exits.
2. **Anchor.** `wpap_pick_anchor()` scores every eligible product against the keyword. Eligibility = published, has a featured image, in stock, not hidden from the catalogue. Products already used, and products whose brand or model appeared in the last `WPAP_VARIETY_WINDOW` articles, sort to the back — unless the keyword names that brand or model explicitly, in which case relevance wins.
3. **Prompt.** The model receives the keyword, the chosen product, related products, the approved internal URLs, the external domain allow-list, and the last 30 headlines with an instruction to differ.
4. **Parse.** Output comes back as `===TITLE===` / `===SLUG===` / `===EXCERPT===` / `===SEO_TITLE===` / `===SEO_DESCRIPTION===` / `===CONTENT===`.
5. **Guard.** HTML is sanitised through `wp_kses`, then every link is rewritten or removed, the keyword is hyperlinked in paragraph one, anchor text is capped, and `{{IMG1}}` becomes a real `<figure>` built from the product's existing attachment.
6. **Validate.** Keyword in headline, no duplicate headline, keyword hyperlinked in paragraph one, 4–6 `<h2>`, at least one valid external link, minimum word count. A failure triggers **one** corrective retry with the reason fed back to the model. A second failure marks the keyword `failed` and publishes nothing.
7. **Publish.** `wp_insert_post`, cover image copied from the pool and registered under the article's own slug, SEO fields written, IndexNow pinged.

---

## Setup

**Requirements:** WordPress 5.8+, WooCommerce, PHP 7.2+ with cURL and GD, an Anthropic API key.

```bash
git clone https://github.com/tahassafi/wp-ai-auto-poster.git
cp wp-ai-auto-poster/wp-auto-poster /path/to/wp-content/plugins/ -r
cp wp-ai-auto-poster/.env.example /path/to/wordpress/.env
```

Fill in `.env`, or — on shared hosting where cron jobs do not inherit environment variables — define the same names as constants in `wp-config.php`:

```php
define( 'WPAP_API_KEY',       'sk-ant-...' );
define( 'WPAP_CRON_SECRET',   '...' );
define( 'WPAP_POST_CATEGORY', 42 );
```

Constants take precedence over environment variables, which take precedence over `.env`. Only `WPAP_API_KEY` is strictly required; every other setting has a working default. The full list is in [`.env.example`](.env.example).

Then activate **WP Auto Poster** in wp-admin. Activation creates three tables: `{prefix}wpap_keywords`, `{prefix}wpap_log`, `{prefix}wpap_anchors`.

**Editorial configuration** lives in [`wp-auto-poster/site-profile.php`](wp-auto-poster/site-profile.php) — what the business sells, house style, which pages may be linked, which external domains are permitted. It is prose, and a non-developer can edit it safely.

**Cover images** (optional) go in `wp-content/uploads/blog-covers/` by FTP. They do not need to be in the Media Library; each run copies one out, renames it to the article slug, registers it as an attachment and sets it as the featured image.

---

## Cron setup

One run per day. The script boots WordPress via `wp-load.php` rather than going through the REST API, which sidesteps authentication entirely for a job already running as a trusted local process.

```
15 5 * * *  /opt/cpanel/ea-php83/root/usr/bin/php -q /home/USER/public_html/wp-content/plugins/wp-auto-poster/cron-post.php
```

Cron uses **server time**, which is frequently UTC — convert from the client's local time before setting the hour. Pin the PHP binary to the version the site runs rather than relying on `/usr/local/bin/php`. The Status screen in wp-admin prints the exact command with the real absolute path already filled in.

For testing, the same script is reachable over HTTP:

```
https://example.com/wp-content/plugins/wp-auto-poster/cron-post.php?key=YOUR_CRON_SECRET
```

CLI runs skip the key check, since `argv` is not web-reachable. **If `WPAP_CRON_SECRET` is unset, HTTP triggering is refused entirely** rather than left open. A transient lock prevents overlapping runs from double-posting.

There is no webhook endpoint; the plugin is outbound-only.

---

## Tests

No WordPress install needed — `tests/stubs.php` provides the dozen core functions the guards touch.

```bash
php tests/guards-test.php        # 30 assertions: parsing, link repair, image, slug, validation
php tests/link-text-test.php     # 12 assertions: catalogue-title shortening, anchor-text cap
php tests/stock-filter-test.php  # 11 assertions: anchor-pool eligibility, against a stubbed $wpdb
```

The stock-filter suite drives the real `wpap_load_catalogue()` against a fake `$wpdb` rather than reimplementing the logic, so the actual SQL-shaped code path is covered.

---

## Design decisions

**Delimiter sections, not JSON.** A 1,200-word HTML article inside a JSON string field breaks constantly — unescaped quotes, stray newlines, a single bad backslash and the whole response is unparseable. `===SECTION===` markers cannot be broken by the payload they delimit. This was the single biggest reliability win.

**The model never picks the product.** Keyword-to-product matching is a scoring function over titles, brands, models and categories. A model asked to choose will happily pick the same photogenic item every time, and cannot know what is in stock. Code picks; the model writes around the choice.

**Rotation is exhaustion-based, not time-based.** The first version held a product back for 120 days. That let a product return while hundreds had never been used. A product now sorts to the back and stays there until every other eligible product has had a turn.

**Variety is tracked at brand and model level, not just product.** Three different parts for the same vehicle are still three articles about that vehicle. Brand and model from the last N articles are demoted — unless the keyword explicitly names them, in which case relevance wins. This shipped after generic keywords produced three consecutive articles about the same car.

**Links are repaired in code, never trusted.** Schemeless `domain.com` becomes `https://domain.com`. External links not on the allow-list lose the `<a>` and keep the text. Internal links to pages that were never approved are unwrapped, so a hallucinated URL cannot become a live 404. Surviving external links get `rel="nofollow noopener" target="_blank"` and are capped at two.

**Anchor text is capped in code.** Catalogue titles run past a dozen words with part numbers and colour codes. A shortener strips filler and part numbers, keeps brand and model at the front and the part noun at the back, and a post-generation pass relabels any link that still exceeds the limit. The in-content image is wrapped in a product link, so its `alt` is anchor text too and gets the same treatment.

**Failure beats bad output.** Every rule is enforced after generation. One corrective retry with the reason fed back, then the keyword is marked `failed` with a readable explanation and nothing is published. An empty slot in the schedule is cheaper than a thin article and a broken link.

**Cover image and product image are always different.** The featured image comes from a rotating pool, renamed to the article slug with article-specific alt text; the product image stays in the body. Same photo in both slots looks like a template.

**Configuration is split by audience.** Secrets resolve from constants → environment → `.env`. Operational tuning sits in `config.php`. Editorial voice sits in `site-profile.php`, in plain prose. Nobody has to open a PHP file they do not understand to change the house style.

**Boot WordPress directly instead of using the REST API.** A cron job on the same filesystem already has every privilege the REST API would authenticate it for. `wp-load.php` skips token management for zero loss of security.

---

## Licence

All rights reserved — published for portfolio and code-review purposes only. See [LICENSE](LICENSE).
