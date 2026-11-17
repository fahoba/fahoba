# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning according to [SemVer](https://semver.org/). Until 1.0.0 a minor
release may still change the plugin API, the HTTP contract and the design
tokens; such a change is named here.

## [Unreleased]

### Added

- A third look, “Abend” (`look: evening`): warm dark mood backdrops,
  translucent hairline cards, teal accent, the rounder Outfit font. It
  shares the glass look's machinery (backdrop, blur on floating panels) and
  the household's backdrop choice; the settings offer the three looks as
  chips instead of a glass switch.
- The menu can float along the bottom on large screens (`wide_menu`:
  `auto`, `side`, `bottom`). `auto` floats it with the evening look, so the
  page gets the whole width without anyone choosing.
- Dashboard tile “Überblick” (`status`): one chip per area — open tasks,
  today's events, what is missing at home, today's meal — each leading to
  its area.
- The tasks tile draws a progress ring around each person; in the evening
  look a finished column turns into a light card and tile titles become
  small spaced capitals — the state reads from across the room.
## [0.4.1] — 2026-11-16

### Fixed

- Nuxt 4.6.4 for GHSA-mjrq-cffv-q4qc and GHSA-r99w-jh2x-3mg9 (dev server
  and nitro server). `pnpm audit` had stopped CI since 2026-11-14, so 0.4.0
  never got its image or its split packages; 0.4.1 is the same release
  with the fix.

## [0.4.0] — 2026-11-16

`fahoba/plugin-api` 0.2.0 adds messages (`Messages\*`), placed tiles
(`Dashboard\PlacedTiles`) and remote photo sources (`Photos\RemotePhotoSource`,
`Photos\PhotoCache`); nothing existing changes. Fahoba's own plugins now
accept `^0.1 || ^0.2` (0.1.1); the new ones need `^0.2`.

### Added

- **Share photos to the app.** On Android, Family Home Base appears in the
  share sheet of the gallery, Google Photos, WhatsApp and others, for
  pictures only, and they go straight into the photo frame. Google closed
  its photo library to other apps in March 2025 and Amazon never opened
  one; sharing is the way in that no service can take away. The pictures
  pass through the usual upload: shrunk on the phone, recognised the
  second time, redrawn without location data. iOS offers no share target
  to web apps.
- **Photo services as plugins.** `fahoba/immich` (an album of the
  family's own Immich), `fahoba/nextcloud-photos` (a Nextcloud folder over
  WebDAV with an app password), `fahoba/cloud-photos` (OneDrive and Dropbox,
  only their app folder — a family grants one folder, not its whole cloud)
  and `fahoba/shared-albums` (an iCloud shared album or a Synology Photos
  share, by public link). Apple and Synology document none of what their
  sharing pages ask; the wizard says so, and a change there costs that
  source, not the frame. Remote pictures are fetched once, redrawn and kept
  in a cache per account (`RemotePhotoSource`, `PhotoCache` in
  `fahoba/plugin-api`, a minor addition): a frame changing every 30 seconds
  would otherwise download the same pictures all day. Servers and NAS must
  be reachable from the internet; one only valid at home is refused, like
  every address a household enters.

- **`fahoba/ics-calendar`: any published iCal address as a calendar.**
  School, kindergarten and club publish a link, Outlook and Google hand
  out a “secret address” — until now only CalDAV with a login or Google
  could bring a foreign calendar onto the wall. One connection per
  address, read-only; the file is read whole when its content changed,
  `DTSTAMP` left out, because some servers stamp every answer anew and the
  calendar would otherwise be rewritten every five minutes.
- **`fahoba/waste`: a tile with the next bins**, from the municipality's
  iCal waste calendar. Tomorrow's collection reads “put it out tonight” —
  the evening is when it matters. The file is fetched at most every six
  hours per address; the tile's own cache asks every minute. On request it
  reminds the evening before — the parents or the whole family, at a time
  chosen in the tile — through the new plugin messages; the same calendar on
  the wall and two phones is one reminder, not three.

- **Plugins can send messages — once the parents allow it.** A plugin
  offers a sender (`offer('messages', …)`, `Messages\MessageSender`) and
  sends through `Messages\Messenger`: to the bell, and to the parents' or
  the whole family's phones. Unlike tiles and providers, installing is not
  enough: parents tick each sender under Household → Messages from
  plugins, because a message on a child's phone reaches into the family
  the way an interface does. Ten messages per sender and household an
  hour; a plugin that loops costs its own messages, not the family's
  evening. `Dashboard\PlacedTiles` lets a scheduled command find a
  plugin's tiles across households. A minor addition to
  `fahoba/plugin-api`; nothing existing changes.

### Changed

- **Arranging the start page works by touch.** On the tablet a drag
  usually ended at once: the browser took the touch as scrolling — the
  grid's or a tile's own list — and cancelled the pointer, and the tile
  stayed where it was. While arranging, a cover over each tile now keeps
  the touch for dragging, the resize handle is finger-sized, and a tile
  over another one shows red before it is let go. The board no longer
  grows rows while dragging: the lowest row is the screen's bottom edge,
  so two photo tiles can stand side by side at full height. A photo tile
  may choose its layout (one picture, two, mosaic) instead of following
  its size.
- **Tiles trade places.** A tile let go on another one used to snap
  back, and rearranging meant parking one tile somewhere free first. Now
  the two swap when each fits the other's spot — shown before letting go,
  saved as one change.
- **Laravel 13.42** — the image build stopped on the advisory against
  13.38 (task output injection in the concurrency process driver,
  CVE-2026-128083). Fahoba does not use that driver, but the build fails
  closed on any advisory, as it should.

- **The family reads “Family Home Base”, written out** — app, admin,
  wordmark, mails, push and the app's name on the home screen. “Fahoba”
  stays the short form for code, repositories and packages: an acronym
  nobody can pronounce at first sight is no name to greet a family with.
  A migration relabels the built-in connections, and an export from 0.3.0
  imports with the new label. Set `APP_NAME="Family Home Base"` in
  `deploy/.env`: mails take their sender's name from it.
- **Opening the app shows the mark and the name at once.** Until the
  texts had loaded the screen stayed blank, and then read “… wird
  geladen” without a picture; it now stands from the first paint (Nuxt's
  loading template) and keeps its place when the app takes over. The home
  screen says “Home Base”: Android cut the full name off.
- **A new mark: a roof over a small house**, in the app's orange on sand.
  Android gets a maskable icon of its own — the rounded tile
  cropped to a circle showed its corners — and the admin's lockup sets the
  mark beside the name without the tile.
- **Redeeming a reward is one popup, not two taps and a name first.**
  Tapping a card opens it: for whom (only who can afford it), how many
  stars they have, what stays after it. Confirming closes it and a second
  one says what was redeemed and what is left — on the wall “asked”, since
  the parents still decide. Before, a child had to tap its own name above
  first, and a parent's account had to tap the card twice; both were found
  too complicated, and the second tap said nothing about the balance.

## [0.3.0] — 2026-11-12

### Renamed: Tagwerk is now Fahoba

For trademark reasons. The name is short for **Fa**mily **Ho**me
**Ba**se. The next release is 0.3.0 — still a minor one, as everything
before 1.0.0: plugin authors must follow the rename, a running server and
its tablets need not.

- **Breaking for plugins.** `Tagwerk\PluginApi` is `Fahoba\PluginApi`,
  `tagwerk/plugin-api` is `fahoba/plugin-api`, `@tagwerk/client` is
  `@fahoba/client`, `extra.tagwerk` in a plugin's `composer.json` is
  `extra.fahoba`, Artisan commands are `fahoba:*`. No aliases: there are
  hardly any third-party plugins yet, and a clean cut costs them less than
  years of two names.
- **What a server holds is carried over.**
  - A migration renames the bundled plugins' keys (`tagwerk/bring` →
    `fahoba/bring`) in every column of the schema, json included —
    connections, favourites, tiles, designs and extensions' settings
    stay. It also renames the built-in calendar's id, without which every
    household would have got a second family calendar.
  - Exports from before still import (`RenamedFromTagwerk`): household
    archives, recipe collections (`tagwerk-recipes`) and the marked secrets
    (`tagwerk_plain_secret`).
- **Paired tablets and signed-in phones stay signed in.** `RenamedCookies`
  reads `tagwerk_device_token`/`tagwerk_user_token`, sets the new cookie and
  clears the old; `theme.js` moves the browser's `tagwerk-*` storage keys
  before anything reads them.
- **Calendars written before keep their people and echo protection:**
  Google's `tagwerk_people`/`tagwerk_revision` and CalDAV's `X-TAGWERK-*`
  are still read, written anew under the new names.
- **Webhooks send `X-Fahoba-*` and, for receivers set up before,
  `X-Tagwerk-*`.**
- **`deploy/.env` may keep `TAGWERK_*`** — the entrypoint and Compose fall
  back to them and log a hint. Except `FAHOBA_HOST` and `FAHOBA_SITE`:
  Compose cannot fall back for a required variable, the start names the old
  one. `TAGWERK_PLUGINS="tagwerk/caldav"` installs `fahoba/caldav`. The
  Compose project, its network and the database defaults keep the name
  `tagwerk`: Compose names the volumes after the project, and a new name
  would have started every server on an empty database.
- **A new mark: a tick inside a house on the tile** —
  and the wordmark in Plus Jakarta Sans 800. The browser tab gets a bold
  variant (`favicon.svg`): the outline blurred at 16 pixels.

### Changed

- **Symfony 8.1.11.** Advisories of 2026-11-04 against `http-foundation`,
  `http-kernel`, `routing` and `html-sanitizer` stopped the image build at
  `composer audit`; the lock now pins the fixed releases.
- **node-forge 1.4.3.** Four advisories against `node-forge` up to 1.4.2
  (via Nuxt's development server, `listhen`) stopped CI at `pnpm audit`; the
  override lifts it.
- **The tapped reward card says it was requested** (“Angefragt – die Eltern
  entscheiden”, framed in the child's colour). The hourglass beside the
  balance sat above the cards, where nobody looked after tapping one below;
  the open requests' list further down was out of sight on the tablet.
  `/api/wall/rewards` adds `reward_id` to each open request for it.

## [0.2.0] — 2026-11-08

A minor release because the HTTP contract grows by `GET /api/statistics`
and the MCP plugin by a tool; nothing that existed changes, a 0.1.0 tablet
keeps working.

### Added

- **Statistics in the admin (Everyday → Statistics).** Who did which task
  how often, as a table that darkens with the count. Beside it: done tasks
  over time per person, the share of their own tasks each person did,
  weekdays, and stars earned against spent. A period, people and one task
  narrow everything; the rows download as CSV (semicolon and BOM, so Excel
  with German settings opens it). Everything is read from completions and
  star entries, nothing new is stored. The share of own tasks counts
  derived occurrences of *active* tasks only: a retired task has no rule
  left to say what was due, so its history shows in the counts but not in
  the share. Gifts between siblings count neither as earned nor as spent.
- **The same statistics in the app, over the API and for an assistant.**
  `GET /api/statistics` (period, dates, people, task) answers with the
  numbers the admin page draws; the app opens them from the menu
  (“Statistik”), the MCP plugin offers them as `task-statistics` with
  names instead of ids. Parents only (`household.manage`): the numbers
  compare children, which a child's phone or the wall in passing should
  not. On the wall it would need the PIN, so the wall offers no entry.
  A custom span is at most two years — every occurrence in it is derived.
- **Requested stars show beside the balance** (hourglass, `−20`). The open
  requests are listed below the rewards, out of sight on a tablet, so a
  child's tap on a reward answered with nothing visible.

### Changed

- **The project lives at `github.com/tagwerk-family`, the image at
  `ghcr.io/tagwerk-family/tagwerk`.** Old addresses under `pointia` redirect
  for git, not for the image: set `TAGWERK_IMAGE` anew.
- **Installing needs no checkout.** The README's setup is a `compose.yaml`
  and a `.env` written in place; the image carries everything else.
- **The Traefik setup pulls the published image too.** `compose.yaml`
  ignored `TAGWERK_IMAGE` and always built on the server; it now follows it
  like the standalone setup, and `tagwerk-app` still builds from the
  checkout.
- **`@tagwerk/client` is published through npm's trusted publishing.** npm
  refuses tokens without two-factor bypass; GitHub's OIDC identity of
  `split.yml` needs no token at all, so none can leak.

### Fixed

- **A tapped notification opens where it is handled.** A reward request
  leads to the stars, the daily summary to the tasks. The messages carried
  no link, so the push fell back to `/`, and the app opened the account's
  start area.
- **The dashboard on an upright tablet.** Below `lg` the twelve columns of
  the landscape grid left every tile some 150 pixels: the clock was cut off,
  names shrank to one letter. There the grid now has six columns, each tile
  keeps its size in columns and is set anew in reading order; arranging stays
  in landscape, the only layout stored.
- **The timetable's times no longer run into the lessons** on a narrow
  screen: their column was too slim for `08:00`.
- **The app shows a favicon.** Its head linked no icon, so browsers asked
  for `/favicon.ico`, which nginx hands to Laravel's empty file; the head now
  names `icon.svg`, which nginx already served for exactly that purpose.

## [0.1.0] — 2026-11-04

The first release. Everything a family uses from day to day, on a wall
tablet and on the phone, from one self-hosted server for several
households. Why it is shaped the way it is: `docs/architecture.md`.

### Added

- **Calendar** — built in, or Google and CalDAV in both directions; day,
  three days, week, month and list, one column per person, school band.
- **Tasks and routines** — taking turns, times of day, one per child,
  paused in holidays; tap, confirm, then “who was that?”; four weeks to
  catch up on from the phone.
- **Stars and rewards** — day bonus, extra stars with a reason, gifts
  between siblings, badges, reward requests; the parent PIN guards the
  wall.
- **Lists** — shopping lists in Tagwerk, mirrored to Bring in both
  directions or pushed to Google Tasks.
- **Meals and recipes** — week plan, portions by who eats, the family's own
  book (typed, from a link, photographed, as a collection), Koch-Wiki and
  Tandoor as sources, ingredients summed onto the list.
- **Timetable** per child with bell schedule, holidays and cancellations.
- **Dashboard** per tablet and per account, weather, resting mode with
  dimming.
- **Photo frame** from uploads and server folders in five layouts per
  device, a library with favourites, albums and memories, a picture sent
  from the phone to the wall.
- **One app** for wall and phone, with push, realtime, light and dark, a
  glass look and backgrounds.
- **Languages** — German and English, more as language packs.
- **Operation** — several households on one server, an operator with
  support access, permissions per person, guests, one account in several
  households, read-only households, export, move and deletion, notices and
  messages, two-factor and social sign-in, a published image with a
  Traefik or a Caddy setup.
- **Plugins** — `tagwerk/plugin-api` (MIT) for providers, dashboard tiles,
  photo rules, whole areas, extensions, notices, language packs and design
  packs; a generator, a testkit and a template repository. Tagwerk's own:
  API tokens, OpenAPI, calendar subscriptions, webhooks, MCP, CalDAV,
  WebDAV backup, Bring, Koch-Wiki, two-factor, social sign-in.
- **Own frontends** — `routes/api-contract.txt` fixes the HTTP contract,
  `@tagwerk/client` (MIT, npm) talks to it, `TAGWERK_FRONTEND` serves
  another frontend at `/`, `tagwerk-frontend-template` is the start.

### Known limitations

- **Signing in with Google from an iPhone home-screen app may land in
  Safari.** iOS keeps the installed app's cookies apart; the way back from
  Google opens in the browser, which then is signed in instead. Android is
  not affected.
- **The dashboard cannot be arranged on a phone.** Below `sm` the tiles
  stack and the pencil is gone; an account arranges its dashboard on a
  tablet or computer and sees the result stacked on its phone.
- **e2e still runs per entry, not per area.** `wall.spec.ts` (tablet, wide)
  and `mobile.spec.ts` (account, narrow) stay as they were; the crossings
  are two smoke tests (`responsive.spec.ts`).
- **Stored content stays in the language it was created in.** Starter
  tasks, rewards and the first shopping list of a new household are rows,
  not texts: they are written in the household's language at creation and do
  not change when someone switches later.
- **Amazon Photos cannot be synced automatically.** There is no interface;
  pictures reach the server folder by download.
- **A recipe's photographed pages do not travel in a collection export.**
