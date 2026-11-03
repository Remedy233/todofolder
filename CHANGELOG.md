# Changelog

All notable changes to TodoFolder are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.0.1] - 2026-11-03

First public release. Published as **0.0.1** on purpose: the store treats the version as
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
