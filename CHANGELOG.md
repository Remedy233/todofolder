# Changelog

All notable changes to TodoFolder are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.0.3] - 2026-11-09

### Changed

- **Refreshed app store screenshots.** The images on the store page still showed the old name and
  the pre-0.0.2 interface; they now show the current app: the task list with a completed task, the
  task dialog, the workspace settings and the admin settings. They also moved to
  `screenshots/app/`, because the store's image cache keeps the old picture for a URL forever.
## [0.0.2] - 2026-11-09

### Added

- **Bilingual UI: English and Chinese, chosen per user.** Every string in the page, the admin
  settings, the Deck cards and the error messages is now English source text with a Chinese
  translation in `l10n/zh_CN.{json,js}` (and `zh-CN.*`, because the JavaScript runtime asks
  for the canonical spelling). A user whose Nextcloud language is Chinese sees Chinese,
  everybody else gets English, no configuration needed. The model prompt follows the same
  language, so an English user gets English titles, summaries and tags.
- **Deck completion mirrors into the task list.** When the card of a task leaves the bound
  column, is marked done or is archived, the task gets the new status `已完成` (shown with a
  green badge and a struck-through title); moving the card back clears it again. Deck stays
  the source of truth: the app only mirrors it, and a rescan no longer queues a finished task
  for analysis again.

### Fixed

- **A finished Deck card no longer comes back.** The sync used to pass the bound stack id and
  order `0` on every run, so a card a human had dragged to *Done* (or marked done, or
  archived) was pulled back to the todo column and reopened by the next scan or re-analysis.
  Deck is now the source of truth for placement and completion: a card is refreshed only
  while it still sits in the column this app put it in and is neither done nor archived;
  everything else is left untouched (and logged).
- **Card position is preserved.** Refreshing an unchanged card keeps its `order` instead of
  moving it to the top of the column.
- **Background and occ runs can write to Deck again.** `occ todofolder:analyze` and the
  background job run without a user session, and Deck's services capture the acting user when
  they are constructed, so every write was refused with *Permission denied*. Those paths now
  write through Deck's mappers; labels, assignee and attachments are still refreshed by the
  web-triggered path.
## [0.0.1] - 2026-11-09

First public release, published to the Nextcloud app store on 2026-11-09
(<https://apps.nextcloud.com/apps/todofolder>). Published as **0.0.1** on purpose: the store treats the version as
Semantic Versioning, and 0.x is the honest signal for "usable, still moving".

### Changed

- **App id `tasknest` → `todofolder`**, display name **TodoFolder** (中文名仍是「待办夹」).
  The namespace is now `OCA\TodoFolder`, tables are `oc_todofolder_*`, routes live under
  `/apps/todofolder`, commands are `occ todofolder:scan` / `todofolder:analyze`, bundles are
  `js/todofolder-*.mjs`.
- Summary and description are bilingual from here on.

### Migration (existing installs)

```sql
RENAME TABLE oc_tasknest_workspaces      TO oc_todofolder_workspaces,
             oc_tasknest_workspace_shares TO oc_todofolder_workspace_shares,
             oc_tasknest_tasks            TO oc_todofolder_tasks,
             oc_tasknest_task_tags        TO oc_todofolder_task_tags;
UPDATE oc_migrations SET app = 'todofolder' WHERE app = 'tasknest';
UPDATE oc_appconfig  SET appid = 'todofolder' WHERE appid = 'tasknest';
```

then deploy the new directory as `custom_apps/todofolder`, `occ app:enable todofolder` and
`occ upgrade`. AI settings, workspaces, tasks, tags and Deck links carry over unchanged.
## [1.3.0] - 2026-11-02

First public release.

### Added

- **Workspaces**: point a workspace at any folder in your Files; every subfolder becomes one
  task, the files inside it are the material. Folders named `_*` or `.*` are ignored.
- **AI analysis**: OpenAI-compatible endpoint (configurable base URL, model, key, timeout).
  Produces title, summary, due date (relative phrases such as "next Friday" and "end of
  month" are resolved), priority and tags. Deterministic mock mode when no endpoint is set,
  so the whole chain can be tested without a model.
- **System tags**: analysis results are written to Nextcloud's own system tags and assigned
  to the task folder, so Files can filter by them.
- **Deck sync**: one card per task — title, description with material links, due date,
  colour, board labels mirroring the tags, assignee, and the task folder attached as a file
  attachment. One-click board setup reuses or creates a board and a "To do" stack.
- **Editable results**: title, received date, due date, priority and tags can be corrected in
  the UI. Edited fields are locked, so a later analysis no longer overwrites them.
- **Received date**: defaults to the day the folder first appeared, editable, and travels to
  Deck as the card's start date.
- **Scanning**: manual from the UI, on a schedule (background job, interval configurable), or
  from the command line (`occ todofolder:scan`, `occ todofolder:analyze`).
- **Sharing**: workspaces can be shared with groups, read-only or read-write.
- Admin settings section using the standard settings layout.

### Security

- Every route is deny-by-default; read-only shares cannot mutate tasks, and only the owner
  may rename, move or delete a workspace.
- The API key is write-only in the UI and never rendered back to the browser.
- Folder paths are resolved inside the caller's own files tree; traversal and cross-tenant
  workspace access answer 404/400 instead of leaking existence.

[1.3.0]: https://github.com/yg/todofolder/releases/tag/v1.3.0
