# BannerPromote blog: writing guide

Every article lives in this folder as `YYYY-MM-DD-slug.html`: front matter between
`---` lines, then the article body as HTML. `tools/build-blog.php` turns it into a
page with structured data, a table of contents, related articles, the RSS feed and a
sitemap entry. **The build refuses to publish anything that breaks the rules below**;
check a draft with:

    php tools/build-blog.php --check-file=content/blog/2026-11-09-my-article.html

Articles dated in the future stay hidden until that date, so posts can be queued.

## Front matter

```
---
title: The H1, written for readers (max 100 chars)
seo_title: The <title> tag, 30-60 chars, starts with the primary keyword if possible
description: 120-160 chars, includes the primary keyword, says what the reader gets
slug: two-to-twelve-words-with-primary-keyword
date: 2026-11-09
updated: (optional) YYYY-MM-DD when meaningfully revised
primary_keyword: the main search phrase, lowercase
keywords: primary keyword, 2-9 related long-tail phrases, comma separated
category: one of: Advertising trends, Banner advertising, Advertising strategy, Seasonal marketing, SEO and search, Small business marketing
---
```

## Body rules (checked automatically)

- At least **1,000 words**, at least **four `<h2>` sections**, no `<h1>`.
- Primary keyword in the seo_title, description, first 150 words and at least one `<h2>`;
  used naturally, **under 3% density** (no keyword stuffing).
- At least **3 internal links** (`href="/pricing.php"`, `/banner-ad-sizes.php`,
  `/how-to-buy-banner-ads.php`, `/faq.php`, `/advertise.php`, other `/blog/....html` articles).
- At least **2 external sources** (`https://` links to the original study, report or announcement).
- A FAQ section the builder turns into FAQPage schema:
  `<section class="faq"><h2>...</h2><details><summary>Question?</summary><p>Answer.</p></details> ...</section>`
  with at least 3 questions people actually search.
- No scripts, iframes, forms, inline styles, placeholder text, or "as an AI" phrasing.
  Every `<img>` needs `alt` text.

## Quality standards (Google's helpful-content and spam policies)

1. **Be useful first.** Answer a real question a small-business advertiser has. Give
   practical steps, examples, checklists or comparisons a reader can act on today.
2. **Facts must be real.** Every statistic, date and claim comes from a source you read
   during research, linked in the text. Never invent numbers, quotes, studies or
   experiences. If you can't verify it, leave it out. Label illustrations as examples.
3. **Be honest about BannerPromote.** It sells flat-rate banner placements ($10 for 30
   days, 8 standard sizes, human review). Don't claim traffic numbers, audience size,
   reporting dashboards or results it doesn't provide. Advertisers measure results
   with their own analytics and UTM-tagged links.
4. **Target one search intent per article.** Pick a primary keyword with clear intent
   and 3-9 closely related long-tail phrases. Don't overlap an existing article's
   primary keyword; link to it instead.
5. **Structure for readers and AI search:** a "Key takeaways" box
   (`<div class="key-takeaways"><h2>Key takeaways</h2><ul>...</ul></div>`), a direct
   answer in the first paragraph, descriptive `<h2>`/`<h3>` headings, short paragraphs,
   lists and tables where they help.
6. **Write plainly.** Active voice, short sentences, no hype ("revolutionary",
   "game-changing"), no filler intros. US English.
7. **End with a soft call to action** linking to `/advertise.php` or `/pricing.php`.
8. **Topic mix.** Rotate between trends and news (what changed this month and what it
   means), evergreen how-tos, comparisons, and seasonal planning about 4-6 weeks ahead
   of the season.
