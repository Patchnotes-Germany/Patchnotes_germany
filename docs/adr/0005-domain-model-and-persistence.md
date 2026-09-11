# 0005. Domain model and persistence rules

- **Status:** accepted
- **Date:** 2026-09-12
- **Milestone:** M1
- **Spec reference:** SPEC.md § 17, § 24.1, § 24.4, § 24.7, § 24.8, § 24.9, § 24.11

## Context

MySQL is a cache and index for content that lives in git, plus the only home of user data.
That split, and the rules of § 24, decide how the tables are shaped.

## Decision

**Natural keys where git has one.** `law_change.id` is the change id of § 24.1
(`2026-bund-bgbl-i-123`), `jurisdiction.code` is the jurisdiction code, `feature_flag.flag_key` and
`setting.setting_key` are their keys. This is what lets `patchnotes:rebuild-from-git` restore
derived rows without mapping tables. Everything else uses surrogate integer ids with unique
constraints on the natural key (`law(jurisdiction, slug)`, `norm(law, norm_key)`, `bill(dip_id)`,
`card(subject_type, subject_id, lang)`).

**Reserved words and collations.** `Change` maps to table `law_change`, `order` became `position`,
`key`/`group` became `norm_key`, `tag_key`, `tag_group`, `flag_key`, `setting_key`, `consent_type`.
Text columns use `utf8mb4_0900_ai_ci`; identifiers, slugs, hashes, tokens and language codes use
`ascii_bin`; German terms and abbreviations use `utf8mb4_bin`, so `Straße` and `Strasse` stay
different keys.

**Time.** Instants are `datetime_immutable` in UTC (`date.timezone = UTC` in the image). Legal dates
— promulgation, entry into force, date of issue — are `date_immutable` without a zone, and are never
derived from git metadata (SPEC.md § 4.5, § 17).

**Generalised cards (§ 24.7).** One `card` table keyed by `(subject_type, subject_id, lang)` serves
changes, bills, digests and plenary summaries; `Bill` carries its own `pipeline_state`,
`review_state`, `audience`, `topics`, `impact` and `lands`, exactly like a change.

**Stage vs. runtime state (§ 24.6).** Only the legislative stage is persisted as given
(`discussed … withdrawn`); `in_force`/`partially_in_force`/`upcoming` live in
`law_change.effective_state` as a cache computed daily from `dates.effective`, together with
`first_effective_date` so calendars and reminders can be queried in SQL.

**Change ↔ ChangeRequest is one-to-many (§ 24.4).** There is no `laws_pr_url` column: one act may
reach different consolidated laws on different days, and preview pull requests add further rows.

**Encrypted profile tags (§ 10, § 24.8).** `user_profile.tags` and `telegram_link.tags` use a custom
Doctrine type `encrypted_json` (libsodium secretbox, key `APP_ENCRYPTION_KEY` injected into the type
at bundle boot). `land`, `lang` and `topics` stay in clear columns so SQL can pre-filter before tags
are matched in PHP in batches. `answered_groups` records which onboarding groups a person actually
answered, because an unanswered group may never produce a direct match.

**Notifications (§ 24.9).** `notification.idempotency_key` is
`(recipient, kind, channel, subject, variant)` and is unique, which makes replays safe. The minimal
`delivery_index` `(recipient_key, subject_id, kind)` outlives the detailed rows so corrections reach
the right people; on account deletion its recipient key is anonymised rather than removed.

**Primary vs. derived data (§ 24.11).** Primary (never restored from git): users, profiles,
consents, notifications and the delivery index, `ai_job`, `ai_usage`, `source_run`,
`source_document`, tokens, settings. Derived: laws, norms, versions, changes, bills, cards,
digests, glossary, taxonomy, change requests. Cache: `norm_translation`,
`amending_act.extracted_json`, rendered card HTML, search indexes.

## Consequences

- Rebuilding content from git touches only the derived tables; user data is untouched.
- Queries that need to filter by audience cannot do it purely in SQL — that is the price of
  encrypting residence status, and the matcher is designed for batched decryption (M8/M9).
- Billing entities (`Plan`, `Subscription`) are deliberately not created yet: the feature is off by
  default and arrives in M12, so the schema stays free of unused tables until then.

## Alternatives considered

- **Surrogate ids everywhere** — rejected: rebuild-from-git would need a persistent id mapping.
- **Storing profile tags in clear with database-level encryption** — rejected: it protects against
  stolen disks only, not against application-level access, and § 10 asks for application-level
  encryption of exactly these fields.
