# Project conventions

Read [WORKFLOW.md](WORKFLOW.md) before you implement or change a provider. It
holds the category contract and the provider acceptance criteria.

## Public repository

This repository is public. Anyone can read everything in it and everything
posted to it: code, comments, docblocks, docs, commit messages, branch names,
issues, PRs and review comments. Never include:

- Internal document content: internal specs, design notes, meeting notes or
  ticket text. Reference an internal issue by bare ID only (see below).
- Internal URLs, hostnames or IP addresses, or links to private repositories,
  dashboards and tools.
- Customer details, including B2B customers and their end customers: names,
  brands, domains, email addresses, order, account or certificate IDs.
- Third-party names other than the providers this package implements. Name an
  implemented provider only in the context of its integration.
- Credentials: API keys, tokens, passwords, client certificates or private
  keys, and real configuration values.
- Commercial details: prices, contract terms, account entitlements.
- Unredacted provider requests, responses or logs. Replace real values with
  placeholders such as `example.com` before you quote them.

An implemented provider's public documentation and API reference are fine to
cite. When in doubt, leave it out and ask.

## Writing style

Write every text a human reads — chat replies, code comments, docblocks,
commit messages, PR bodies, docs — in terse Simplified Technical English (STE):

- One idea per sentence. Short sentences. Active voice, present tense.
- State each fact or rule once. Do not restate it, pad it, or explain what the
  code already shows.
- Cut hedging and filler ("essentially", "it's worth noting", "in order to",
  "please note that").
- A docblock says what a thing is and any non-obvious constraint — not a
  narrative. If a comment runs longer than the code it describes, cut it.

## Issue references

Reference an internal issue by its bare ID only (e.g. `ABC-123`) in branch
names, commit messages, and PR titles and bodies. Do not link to the issue
tracker or name it. A link can make the tracker's GitHub integration copy
ticket content into GitHub.

GitHub turns `#123`, `GH-123` and `owner/repo#123` into links. Use them only for
this repository's own issues and PRs. Never write an internal ID or any other
number in these forms: GitHub links it to an unrelated issue or PR. Write `@`
only to mention a GitHub user on purpose.
