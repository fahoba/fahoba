import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { devices, expect, test } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'
import { adminSession, createAccount, login, openSignIn, pair, tinker } from '../tests/helpers'
import { borrowLocation, menu, morning, returnLocation, settle, theme } from './shots'

/*
 * Pictures for the marketing website:
 *   WEBSITE_SHOTS=<dir>/ pnpm exec playwright test -c screenshots.config.ts website.ts
 * The website repository is expected beside this one (WEBSITE_SHOTS overrides).
 * Photos come from PHOTO_DIR (the credited Wikimedia files of the website's
 * CREDITS.md, kept in storage/app/website-photos, not in Git); afterAll
 * removes the rows again.
 */
const OUT = process.env.WEBSITE_SHOTS ?? new URL('../../../../../../../tagwerk-website/src/assets/screenshots/', import.meta.url).pathname
const PHOTO_DIR = process.env.PHOTO_DIR ?? new URL('../../../../storage/app/website-photos/', import.meta.url).pathname

const PHOTOS: Array<[string, string]> = [
  ['bergsee', 'Bergsee'],
  ['lakekinney', 'Lake Kinney'],
  ['herbstabend', 'Herbstabend'],
  ['apfelbluete', 'Apfelblüte'],
  ['strand', 'Abend am Strand'],
  ['heide', 'Heide'],
  ['sonnenuntergang', 'Sonnenuntergang am See'],
]

const REPO = new URL('../../../../../..', import.meta.url).pathname
const WALL = { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 }

/** The dev-only example plugin adds a menu entry a real household does not have. */
async function hideExample(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Beispiel', exact: true }).evaluateAll(buttons => {
    for (const button of buttons) {
      (button as HTMLElement).style.display = 'none'
    }
  })
}

/** The demo household's expired Google sample shows a notice bar in the admin; a real one would not. */
async function hideNoticeBar(page: Page): Promise<void> {
  await page.evaluate(() => {
    const all = [...document.querySelectorAll('body *')] as HTMLElement[]
    const hit = all.filter(el => el.children.length === 0 || el.tagName === 'SPAN').find(el => el.textContent?.includes('Verbindung zu Google Kalender'))
    let node: HTMLElement | null = hit ?? null
    while (node && node.parentElement && node.parentElement !== document.body) {
      const box = node.getBoundingClientRect()
      const parent = node.parentElement.getBoundingClientRect()
      if (box.width >= window.innerWidth - 2 && parent.height > box.height + 40) break
      node = node.parentElement
    }
    if (node) node.style.display = 'none'
  })
}

test.beforeAll(borrowLocation)
test.afterAll(returnLocation)

/** One of the three moods the website shows side by side. */
type Mood = { suffix: '' | '-dark' | '-evening', look: 'glass' | 'evening', mode: 'Hell' | 'Dunkel' }
const MOODS: Mood[] = [
  { suffix: '', look: 'glass', mode: 'Hell' },
  { suffix: '-dark', look: 'glass', mode: 'Dunkel' },
  { suffix: '-evening', look: 'evening', mode: 'Dunkel' },
]

/** Opens one page of the device settings. */
async function settings(page: Page, topic: string): Promise<Locator> {
  await page.getByTestId('theme').click()
  const sheet = page.getByTestId('settings-sheet')
  await sheet.locator(`[data-testid="settings-page"][data-value="${topic}"]`).click()

  return sheet
}

async function closeSettings(page: Page): Promise<void> {
  while (await page.getByTestId('settings-sheet').count() > 0) {
    await page.keyboard.press('Escape')
  }
}

/** Picks a chip, or turns a switch on, only when it is not already so (no request otherwise). */
async function choose(page: Page, sheet: Locator, cell: string, value?: string): Promise<void> {
  const target = sheet.locator(`[data-testid="preference"][data-cell="${cell}"]${value ? `[data-value="${value}"]` : ''}`).first()
  const state = await target.getAttribute(value ? 'aria-pressed' : 'aria-checked')

  if (state !== 'true') {
    await Promise.all([
      page.waitForResponse(r => r.url().endsWith('/api/preferences') && r.request().method() === 'PUT'),
      target.click(),
    ])
  }
}

/** Look, backdrop and light or dark, set the way a tap in the device settings sets them. */
async function mood(page: Page, { look, mode }: Mood): Promise<void> {
  const sheet = await settings(page, 'appearance')
  await choose(page, sheet, 'look', look)
  await choose(page, sheet, 'backdrop', 'dusk')
  await choose(page, sheet, 'colored_icons')

  const theme = mode === 'Hell' ? 'light' : 'dark'
  if (await page.locator('html').getAttribute('data-theme') !== theme) {
    await Promise.all([
      page.waitForResponse(r => r.url().endsWith('/api/theme') && r.request().method() === 'PUT'),
      page.getByRole('radio', { name: mode }).click(),
    ])
  }
  await expect(page.locator('html')).toHaveAttribute('data-theme', theme)
  await expect(page.locator('html')).toHaveAttribute('data-look', look)
  await closeSettings(page)
}

/** The side menu for every mood, so the three pictures line up when the website splits them. */
async function sideMenu(page: Page): Promise<void> {
  const sheet = await settings(page, 'start')
  await choose(page, sheet, 'wide_menu', 'side')
  await closeSettings(page)
}

/*
 * The demo timetable has no subject colours; the website shows one per
 * subject. Only lessons without a colour get one, and lose it again after.
 */
const SUBJECTS: Record<string, string> = {
  Mathematik: '#3b82f6', Deutsch: '#ef4444', Englisch: '#8b5cf6', Sachunterricht: '#22c55e',
  Biologie: '#14b8a6', Geografie: '#a16207', Turnen: '#f97316', Musik: '#ec4899',
  Chor: '#eab308', Werken: '#64748b', Werkstatt: '#64748b',
}
let colouredLessons = '[]'
let borrowedMeals = '[]'

/*
 * The demo week's meals sit in the week the seeder ran. When this week has
 * none, the demo household's first planned week is copied into it, and the
 * copies are removed again after.
 */
test.beforeAll(() => {
  borrowedMeals = tinker(
    '$t = DB::table("meal_plan_entries"); $h = App\\Models\\Household::orderBy("id")->first()->id;'
    + ' $week = now()->startOfWeek(); $ids = [];'
    + ' if (! (clone $t)->where("household_id", $h)->whereBetween("planned_on", [$week->toDateString(), $week->copy()->addDays(6)->toDateString()])->exists()) {'
    + '   $from = Carbon\\Carbon::parse((clone $t)->where("household_id", $h)->where("title", "Gemüselasagne")->min("planned_on"))->startOfWeek();'
    + '   foreach ((clone $t)->where("household_id", $h)->whereBetween("planned_on", [$from->toDateString(), $from->copy()->addDays(6)->toDateString()])->get() as $row) {'
    + '     $copy = (array) $row; unset($copy["id"]);'
    + '     $copy["planned_on"] = $week->copy()->addDays((int) $from->diffInDays(Carbon\\Carbon::parse($row->planned_on)))->toDateString();'
    + '     $ids[] = (clone $t)->insertGetId($copy);'
    + '   }'
    + ' }'
    + ' echo json_encode($ids);',
  ).trim().split('\n').pop() ?? '[]'
})
test.afterAll(() => {
  tinker(`DB::table("meal_plan_entries")->whereIn("id", json_decode("${borrowedMeals.replaceAll('"', '\\"')}", true) ?: [])->delete();`)
})

test.beforeAll(() => {
  colouredLessons = tinker('echo json_encode(DB::table("lessons")->whereNull("color")->pluck("id"));').trim().split('\n').pop() ?? '[]'
  for (const [title, color] of Object.entries(SUBJECTS)) {
    tinker(`DB::table("lessons")->whereNull("color")->where("title", "${title}")->update(["color" => "${color}"]);`)
  }
})
test.afterAll(() => {
  tinker(`DB::table("lessons")->whereIn("id", json_decode("${colouredLessons.replaceAll('"', '\\"')}", true) ?: [])->update(["color" => null]);`)
})


/*
 * The phone upload answers 500 for these full-size pictures in DDEV (php-fpm
 * dies without a log line), so the rows are made the way wall.spec.ts makes
 * its own: the file goes through PhotoImage, the row carries a marker
 * fingerprint, and afterAll removes them again.
 */
const MARKER = 'e2e-site-'

function removePhotos(): void {
  tinker(
    'App\\Models\\Photo::withoutGlobalScope("household")->withTrashed()->where("fingerprint", "like", "' + MARKER + '%")'
    + '->get()->each(function ($photo) { $photo->forceDelete(); });',
  )
}

test.beforeAll(() => {
  removePhotos()

  for (const [file, caption] of PHOTOS) {
    execFileSync('ddev', ['exec', 'cat > /tmp/site-photo.jpg'], { cwd: REPO, input: readFileSync(`${PHOTO_DIR}${file}.jpg`) })
    tinker(
      '$content = file_get_contents("/tmp/site-photo.jpg");'
      + ' App\\Models\\Photo::withoutGlobalScope("household")->forceCreate(['
      + '"household_id" => App\\Models\\Household::orderBy("id")->first()->id,'
      + ' "path" => app(App\\Services\\PhotoImage::class)->store($content),'
      + ` "caption" => "${caption}", "fingerprint" => "${MARKER}${file}"]);`,
    )
  }
})
test.afterAll(removePhotos)

test.describe('wall', () => {
  test.use(WALL)

  test('wall in three moods', async ({ page }) => {
    test.setTimeout(300_000)
    await morning(page)
    await pair(page)
    await sideMenu(page)
    const area = (name: string) => page.getByRole('button', { name, exact: true }).click()
    const shot = async (name: string): Promise<void> => {
      await settle(page)
      await hideExample(page)
      await page.screenshot({ path: `${OUT}${name}.png` })
    }
    const views: Array<[string, string, () => Promise<void>]> = [
      ['Start', 'wall-dashboard', () => expect(page.getByTestId('dashboard-grid')).toBeVisible()],
      ['Kalender', 'wall-calendar-3day', () => expect(page.getByTestId('event').first()).toBeVisible()],
      ['Aufgaben', 'wall-tasks', () => expect(page.getByTestId('column-open').first()).toBeVisible()],
      ['Essen', 'wall-meals', () => expect(page.getByTestId('meal').first()).toBeVisible()],
      ['Listen', 'wall-lists', async () => {}],
      ['Schule', 'wall-timetable', async () => {}],
      ['Sterne', 'wall-points', () => expect(page.getByTestId('reward').first()).toBeVisible()],
    ]

    for (const m of MOODS) {
      await mood(page, m)
      for (const [name, file, ready] of views) {
        await area(name)
        await ready()
        await shot(`${file}${m.suffix}`)
      }
    }

    // The frame turns to the next picture on its own, so its three moods are taken in a row.
    await area('Fotos')
    await expect(page.getByTestId('photo-frame')).toBeVisible()
    await page.waitForFunction(() => [...document.querySelectorAll('[data-testid="photo-frame"] img')].some(i => (i as HTMLImageElement).complete && (i as HTMLImageElement).naturalWidth > 0), undefined, { polling: 500 })
    await page.waitForTimeout(1500)
    for (const m of MOODS) {
      await mood(page, m)
      await page.waitForTimeout(900)
      await page.screenshot({ path: `${OUT}wall-photos${m.suffix}.png` })
    }

    // The shared task, asked who did it. Nothing is saved: the dialog is cancelled.
    await mood(page, MOODS[0])
    await area('Aufgaben')
    await page.getByTestId('day-column').last().getByRole('button', { name: /Geschirrspüler ausräumen/ }).click()
    await page.getByTestId('task-details-complete').click()
    await expect(page.getByTestId('completed-person').first()).toBeVisible()
    await shot('wall-task-who-picker')
    await page.getByRole('button', { name: 'Abbrechen' }).click()
  })

  test('wall screensaver', async ({ page }) => {
    test.setTimeout(120_000)
    const day = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Vienna' }).format(new Date())
    const offset = new Intl.DateTimeFormat('en-US', { timeZone: 'Europe/Vienna', timeZoneName: 'longOffset' })
      .formatToParts(new Date()).find(part => part.type === 'timeZoneName')?.value.replace('GMT', '') ?? '+00:00'
    await page.clock.install({ time: new Date(`${day}T09:30:00${offset}`) })
    await pair(page)
    await mood(page, MOODS[0])
    await page.getByTestId('theme').click()
    const dialog = page.getByTestId('settings-sheet')
    await dialog.locator('[data-testid="settings-page"][data-value="rest"]').click()

    for (const rest of await dialog.getByTestId('rest-area').all()) {
      const photos = await rest.getAttribute('data-value') === 'photos'
      const pressed = await rest.getAttribute('aria-pressed') === 'true'

      if (photos !== pressed) {
        await Promise.all([
          page.waitForResponse(r => r.url().endsWith('/api/preferences') && r.request().method() === 'PUT'),
          rest.click(),
        ])
      }
    }

    await Promise.all([
      page.waitForResponse(r => r.url().endsWith('/api/preferences') && r.request().method() === 'PUT'),
      dialog.locator('[data-testid="preference"][data-cell="screensaver_idle"][data-value="1"]').click(),
    ])
    await page.keyboard.press('Escape')
    await page.keyboard.press('Escape')

    await page.clock.runFor(61_000)
    await expect(page.getByTestId('screensaver')).toBeVisible()
    await expect(page.getByTestId('photo-clock')).toBeVisible()
    await page.waitForFunction(() => [...document.querySelectorAll('[data-testid="photo-frame"] img')].some(i => (i as HTMLImageElement).complete && (i as HTMLImageElement).naturalWidth > 0), undefined, { polling: 500 })
    await page.waitForTimeout(2500)
    await page.waitForTimeout(1500)
    await page.screenshot({ path: `${OUT}wall-screensaver.png` })
  })
})

test.describe('wall portrait', () => {
  test.use({ viewport: { width: 800, height: 1280 }, deviceScaleFactor: 2 })

  test('portrait dashboard', async ({ page }) => {
    await morning(page)
    await pair(page)
    await mood(page, MOODS[0])
    await page.getByRole('button', { name: 'Start', exact: true }).click()
    await expect(page.getByTestId('dashboard-grid')).toBeVisible()
    await settle(page)
    await hideExample(page)
    await page.screenshot({ path: `${OUT}wall-dashboard-portrait.png` })
  })
})

test.describe('phone', () => {
  test.use({ userAgent: devices['Pixel 7'].userAgent, isMobile: true, hasTouch: true, viewport: { width: 390, height: 794 }, deviceScaleFactor: 2.775 })

  test('phone', async ({ page }) => {
    test.setTimeout(120_000)
    await morning(page)
    await openSignIn(page)
    await login(page, createAccount())

    const visit = async (area: string, name: string, suffix = ''): Promise<void> => {
      await (await menu(page)).getByTestId('tab').filter({ hasText: area }).click()
      await settle(page)
      await page.screenshot({ path: `${OUT}${name}${suffix}.png` })
    }
    const mode = (value: 'Hell' | 'Dunkel') => theme(page, value, async (p: Page) => {
      await menu(p)
      await p.getByTestId('settings').click()
    })

    await mode('Hell')
    await visit('Start', 'phone-dashboard')
    await visit('Listen', 'phone-lists')
    await visit('Essen', 'phone-meals')
    await visit('Kalender', 'phone-calendar')
    await mode('Dunkel')
    await visit('Kalender', 'phone-calendar', '-dark')
  })
})

test.describe('admin', () => {
  test.use({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 })

  test('admin', async ({ page }) => {
    test.setTimeout(120_000)
    // The demo has no rotating task; one gets a turn order for the picture and is put back after.
    const original = tinker(
      '$t = App\\Models\\Task::withoutGlobalScope("household")->where("title", "Müll rausbringen")->firstOrFail();'
      + ' echo "id=" . $t->id . ";person=" . json_encode($t->person_id) . ";rotation=" . json_encode($t->rotation);',
    )
    const id = original.match(/id=(\d+)/)![1]
    const person = original.match(/person=(\S*?);/)![1]
    const rotation = original.match(/rotation=(.*)$/m)![1]

    try {
      tinker(
        `$t = App\\Models\\Task::withoutGlobalScope("household")->findOrFail(${id});`
        + ' $order = App\\Models\\Person::withoutGlobalScope("household")->where("household_id", $t->household_id)->where("role", "!=", "guest")->orderBy("id")->pluck("id")->all();'
        + ' $t->forceFill(["person_id" => $order[0], "rotation" => $order])->saveQuietly();',
      )
      await adminSession(page)
      await settle(page)
      await hideNoticeBar(page)
      await page.screenshot({ path: `${OUT}admin-overview.png` })

      await page.goto('/admin/tasks')
      await expect(page.locator('.fi-ta-row').first()).toBeVisible()
      await settle(page)
      await hideNoticeBar(page)
      await page.screenshot({ path: `${OUT}admin-tasks.png` })

      await page.goto(`/admin/tasks/${id}/edit`)
      await expect(page.getByText('Abwechselnd', { exact: true })).toBeVisible()
      await settle(page)
      await page.getByText('Abwechselnd', { exact: true }).first().scrollIntoViewIfNeeded()
      await page.evaluate(() => window.scrollBy(0, 200))
      await hideNoticeBar(page)
      await page.waitForTimeout(300)
      await page.screenshot({ path: `${OUT}admin-task-edit.png` })
    }
    finally {
      tinker(
        `$t = App\\Models\\Task::withoutGlobalScope("household")->findOrFail(${id});`
        + ` $t->forceFill(["person_id" => ${person}, "rotation" => json_decode("${rotation.replaceAll('"', '\\"')}", true)])->saveQuietly();`,
      )
    }
  })
})

/*
 * The tour video: a paired wall, recorded at 1280x800 into TOUR_DIR (the
 * website encodes it). Weather, a child's whole column ticked off with stars
 * and the day bonus, coloured icons, the weather in the menu, dark, and the
 * evening look last. What it ticks off is taken out of the demo household
 * again afterwards.
 */
test.describe('tour video', () => {
  test.use({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 })

  test('tour video', async ({ browser }) => {
    test.setTimeout(240_000)
    const dir = process.env.TOUR_DIR ?? `${tmpdir()}/fahoba-tour`
    const options = { baseURL: 'https://fahoba.test', ignoreHTTPSErrors: true, timezoneId: 'Europe/Vienna', locale: 'de-AT', viewport: { width: 1280, height: 800 } }

    // Pair and set the starting look off camera, then record with the device's cookie.
    const setup = await browser.newContext(options)
    const first = await setup.newPage()
    await morning(first)
    await pair(first)
    const sheet = await settings(first, 'appearance')
    await choose(first, sheet, 'look', 'glass')
    await choose(first, sheet, 'backdrop', 'dusk')
    await closeSettings(first)
    const state = await setup.storageState()
    await setup.close()

    const before = tinker(
      'echo "c=" . App\\Models\\TaskCompletion::withoutGlobalScope("household")->max("id") . ";p=" . App\\Models\\PointEntry::withoutGlobalScope("household")->max("id");',
    )
    const completionFloor = Number(before.match(/c=(\d*)/)?.[1] || 0)
    const pointFloor = Number(before.match(/p=(\d*)/)?.[1] || 0)

    const context = await browser.newContext({ ...options, storageState: state, recordVideo: { dir, size: { width: 1280, height: 800 } } })
    const page = await context.newPage()
    const pause = (ms: number) => page.waitForTimeout(ms)
    const area = (name: string) => page.getByRole('button', { name, exact: true }).click()

    try {
      await morning(page)
      await page.goto('/')
      await expect(page.getByTestId('dashboard-grid')).toBeVisible()
      await settle(page)
      await pause(1200)

      await page.getByTestId('weather-open').click()
      await pause(3000)
      await page.keyboard.press('Escape')
      await pause(1000)

      await area('Aufgaben')
      await expect(page.getByTestId('day-column').first()).toBeVisible()
      await pause(1500)

      const max = page.getByTestId('day-column').filter({ hasText: 'Max' }).first()
      for (let i = 0; i < 8 && await max.getByTestId('column-open-task').count() > 0; i++) {
        await max.getByTestId('column-open-task').first().click()
        await pause(900)
        await page.getByTestId('task-details-complete').click()
        const who = page.getByTestId('completed-person').filter({ hasText: 'Max' })
        if (await who.first().isVisible({ timeout: 800 }).catch(() => false)) {
          await who.first().click()
        }
        await pause(2600)
      }
      await pause(2500)

      let sheet = await settings(page, 'appearance')
      await pause(600)
      await choose(page, sheet, 'colored_icons')
      await pause(1600)
      await closeSettings(page)
      await pause(1500)

      sheet = await settings(page, 'start')
      await pause(600)
      await choose(page, sheet, 'menu_top', 'weather')
      await pause(1600)
      await closeSettings(page)
      await pause(1800)

      sheet = await settings(page, 'appearance')
      await pause(600)
      await page.getByRole('radio', { name: 'Dunkel' }).click()
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
      await pause(1600)
      await closeSettings(page)
      await pause(1200)
      await area('Sterne')
      await pause(2500)

      sheet = await settings(page, 'appearance')
      await pause(600)
      await choose(page, sheet, 'look', 'evening')
      await pause(1600)
      await closeSettings(page)
      await pause(1000)
      await area('Start')
      await expect(page.getByTestId('dashboard-grid')).toBeVisible()
      await pause(3000)
      await area('Aufgaben')
      await pause(3000)
    }
    finally {
      await context.close()
      tinker(
        `App\\Models\\PointEntry::withoutGlobalScope("household")->where("id", ">", ${pointFloor})->delete();`
        + ` App\\Models\\TaskCompletion::withoutGlobalScope("household")->where("id", ">", ${completionFloor})->delete();`,
      )
    }
  })
})
