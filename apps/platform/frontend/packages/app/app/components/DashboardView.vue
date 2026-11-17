<script setup lang="ts">
import type { Tile, Placement, Trade } from '~/utils/grid'
import type { AgendaEvent } from '~/utils/calendar'
import type { Mode } from '~/utils/areas'
import WidgetCalendar from './widgets/WidgetCalendar.vue'
import WidgetClock from './widgets/WidgetClock.vue'
import WidgetGeneric from './widgets/WidgetGeneric.vue'
import WidgetLists from './widgets/WidgetLists.vue'
import WidgetMeals from './widgets/WidgetMeals.vue'
import WidgetMembers from './widgets/WidgetMembers.vue'
import WidgetPhoto from './widgets/WidgetPhoto.vue'
import WidgetRewards from './widgets/WidgetRewards.vue'
import WidgetStars from './widgets/WidgetStars.vue'
import WidgetStatus from './widgets/WidgetStatus.vue'
import WidgetTasks from './widgets/WidgetTasks.vue'
import WidgetWeather from './widgets/WidgetWeather.vue'
import { COLUMNS, HALF_COLUMNS, cellOffset, fits, reflowed, resized, shifted, rows, stacked, traded } from '~/utils/grid'

const { t } = useI18n()

const props = defineProps<{ timeZone: string, today: string }>()

/*
 * Tiles lead on: a person in the tasks tile to their tasks, an event to its
 * dialog. Not while arranging — then a tap means grabbing the tile.
 */
const emit = defineEmits<{ 'pick-event': [AgendaEvent], 'show-tasks': [number], 'show-area': [Mode] }>()

const { tiles, offer, error, load, place, swap, configure, configurePlugin, add, remove } = useDashboard()

/*
 * Mapped statically instead of resolved by name: that way the type checker
 * notices when a key from the server list has no component here — and not
 * only the tablet on the wall.
 */
const COMPONENTS = {
  calendar: WidgetCalendar,
  tasks: WidgetTasks,
  stars: WidgetStars,
  rewards: WidgetRewards,
  members: WidgetMembers,
  meals: WidgetMeals,
  lists: WidgetLists,
  weather: WidgetWeather,
  clock: WidgetClock,
  photo: WidgetPhoto,
  status: WidgetStatus,
} as const

type WidgetKey = keyof typeof COMPONENTS

function component(type: string) {
  return COMPONENTS[type as WidgetKey] ?? null
}

const label = computed(() => new Map(offer.value.map(entry => [entry.type, entry.label])))

// Plugin widgets have no component of their own; their content comes as data.
const generic = computed(() => new Set(offer.value.filter(entry => entry.generic).map(entry => entry.type)))

// A plugin's settings form, by widget type.
const fields = computed(() => new Map(offer.value.map(entry => [entry.type, entry.settings ?? []])))

onMounted(load)

/*
 * Twelve columns do not fit a phone: there the tiles stack in reading order,
 * each as tall as it is on the grid. Arranging happens where the grid fits.
 */
const narrow = useNarrow()

/*
 * Below `lg` — a tablet held upright — twelve columns made every tile a
 * sliver: there the grid has half the columns, each tile twice as wide, set
 * anew in reading order. Arranging stays with the landscape grid, which is
 * the one stored.
 */
const half = useNarrow(1024)
const columns = computed(() => (half.value ? HALF_COLUMNS : COLUMNS))
const shown = computed(() => {
  if (narrow.value) {
    return stacked(tiles.value)
  }

  return half.value
    ? reflowed(tiles.value.map(tile => ({ ...tile, h: Math.max(tile.h, UPRIGHT_MIN_ROWS[tile.type] ?? 0) })))
    : tiles.value
})

/*
 * Twice as wide, the clock's digits grow twice as tall (they follow the
 * tile's width); two rows clipped them on a tablet 1024 pixels high.
 */
const UPRIGHT_MIN_ROWS: Record<string, number> = { clock: 3, weather: 3 }

/** Rows are 72 pixels at least on the grid; a stacked tile keeps that. */
const STACKED_ROW = 72

/*
 * On the grid rows stretch to fill the screen, stacked they do not: these
 * tiles need more rows on a phone than their grid size to show everything.
 */
const STACKED_MIN_ROWS: Record<string, number> = { weather: 3 }

function tileStyle(tile: Tile): Record<string, string> {
  if (narrow.value) {
    return { height: `${Math.max(tile.h, STACKED_MIN_ROWS[tile.type] ?? 0) * STACKED_ROW}px` }
  }

  return {
    gridColumn: `${placementOf(tile).x + 1} / span ${placementOf(tile).w}`,
    gridRow: `${placementOf(tile).y + 1} / span ${placementOf(tile).h}`,
  }
}

/*
 * No dragging without the switch: on a wall in the hallway every accidental
 * swipe would otherwise move half the dashboard.
 */
const editMode = ref(false)
const pickerOpen = ref(false)

// Turned upright while arranging: the grid on screen is no longer the stored one.
watch(half, (upright) => {
  if (upright) {
    editMode.value = false
    pickerOpen.value = false
  }
})

const pinOpen = ref(false)
const pending = ref<(() => Promise<void>) | null>(null)

async function pencilTap(): Promise<void> {
  if (editMode.value) {
    editMode.value = false
    pickerOpen.value = false

    return
  }

  const snapshot = await $fetch<{ required: boolean, unlocked: boolean }>('/api/wall/pin', {
    credentials: 'same-origin',
  }).catch(() => ({ required: false, unlocked: true }))

  if (snapshot.required && !snapshot.unlocked) {
    pending.value = async () => {
      editMode.value = true
    }
    pinOpen.value = true

    return
  }

  editMode.value = true
}

async function afterUnlock(): Promise<void> {
  pinOpen.value = false

  const job = pending.value

  pending.value = null

  await job?.()
}

const raster = ref<HTMLElement | null>(null)

/*
 * The board is as many rows as its tiles need, six at least, and those rows
 * share the screen's height. Dragging never adds a row: when it did, every
 * tile shrank under the finger and "full height" was a moving target.
 */
const rowCount = computed(() => rows(shown.value))

/** The drag currently in progress. Null means nobody is dragging. */
const drag = ref<{
  id: number
  art: 'ziehen' | 'groesse'
  startX: number
  startY: number
  origin: Placement
  placement: Placement
  /** Dropped onto one other tile that can take the origin: the two trade places. */
  trade: Trade | null
} | null>(null)

/** The placement shown: the preview while dragging, otherwise the stored one. */
function placementOf(tile: Tile): Placement {
  if (drag.value?.trade?.id === tile.id) {
    return drag.value.trade.placement
  }

  if (drag.value?.id === tile.id) {
    return drag.value.trade?.moved ?? drag.value.placement
  }

  return tile
}

/** Taken from the scroll height: a board taller than the screen scrolls. */
function cellSize(): { width: number, height: number } {
  return {
    width: (raster.value?.clientWidth ?? 0) / COLUMNS,
    height: (raster.value?.scrollHeight ?? 0) / rowCount.value,
  }
}

/** The tile under the finger would land on another one. */
const blocked = computed(() => drag.value !== null && drag.value.trade === null
  && !fits(drag.value.placement, tiles.value, drag.value.id))

function grab(tile: Tile, art: 'ziehen' | 'groesse', event: PointerEvent): void {
  if (!editMode.value) {
    return
  }

  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
  error.value = null

  drag.value = {
    id: tile.id,
    art,
    startX: event.clientX,
    startY: event.clientY,
    origin: { x: tile.x, y: tile.y, w: tile.w, h: tile.h },
    placement: { x: tile.x, y: tile.y, w: tile.w, h: tile.h },
    trade: null,
  }
}

function move(event: PointerEvent): void {
  if (drag.value === null) {
    return
  }

  const { width, height } = cellSize()
  const dx = cellOffset(event.clientX - drag.value.startX, width)
  const dy = cellOffset(event.clientY - drag.value.startY, height)

  drag.value.placement = drag.value.art === 'ziehen'
    ? shifted(drag.value.origin, dx, dy, rowCount.value)
    : resized(drag.value.origin, dx, dy, rowCount.value)

  // Only a moved tile trades; a resized one would push its neighbour away.
  drag.value.trade = drag.value.art === 'ziehen' && !fits(drag.value.placement, tiles.value, drag.value.id)
    ? traded(drag.value.origin, drag.value.placement, tiles.value, drag.value.id, rowCount.value)
    : null
}

/* A cancelled pointer (the system took the touch) snaps back: `pointercancel` sets `drag` to null. */
async function release(): Promise<void> {
  const run = drag.value

  drag.value = null

  if (run === null) {
    return
  }

  const unchanged = run.placement.x === run.origin.x && run.placement.y === run.origin.y
    && run.placement.w === run.origin.w && run.placement.h === run.origin.h

  if (unchanged) {
    return
  }

  const tile = tiles.value.find(entry => entry.id === run.id)

  if (tile && run.trade) {
    const trade = run.trade

    await mitPin(() => swap(tile, trade.moved, trade.id, trade.placement))

    return
  }

  // Nothing is pushed aside: what neither fits nor trades snaps back.
  if (!tile || !fits(run.placement, tiles.value, run.id)) {
    error.value = t('dashboard.occupied')

    return
  }

  await mitPin(() => place(tile, run.placement))
}

async function mitPin(job: () => Promise<'ok' | 'pin' | 'fehler'>): Promise<void> {
  if (await job() === 'pin') {
    pending.value = async () => {
      await job()
    }
    pinOpen.value = true
  }
}

async function createTile(type: string): Promise<void> {
  pickerOpen.value = false

  await mitPin(() => add(type))
}

/** Only the calendar has a view to choose: hour grid or list. */
async function toggleCalendarView(tile: Tile): Promise<void> {
  const view = tile.settings.view === 'list' ? 'day' : 'list'

  await mitPin(() => configure(tile, { ...tile.settings, view }))
}

/* A photo tile draws from its own source: everything, favourites, albums or memories. */
const photoTile = ref<Tile | null>(null)

async function choosePhotoSource(settings: Record<string, unknown>): Promise<void> {
  const tile = photoTile.value
  photoTile.value = null

  if (tile) {
    await mitPin(() => configure(tile, settings))
  }
}

/* A plugin tile's settings, drawn from the fields it declares. */
const settingsTile = ref<Tile | null>(null)

async function savePluginSettings(settings: Record<string, unknown>): Promise<void> {
  const tile = settingsTile.value
  settingsTile.value = null

  if (tile) {
    await mitPin(() => configurePlugin(tile, settings))
  }
}

async function removeTile(id: number): Promise<void> {
  await mitPin(() => remove(id))
}
</script>

<template>
  <div class="relative flex h-full min-h-0 flex-col">
    <p v-if="error" role="alert" class="mb-2 text-base text-danger">
      {{ error }}
    </p>

    <!--
      Floating in the corner instead of a bar of its own: the row above the
      grid cost a whole strip of the wall for one button.
    -->
    <div v-if="!half" class="absolute right-3 bottom-3 z-10 flex items-center gap-2">
      <button
        v-if="editMode"
        type="button"
        data-testid="widget-picker"
        class="flex min-h-12 items-center gap-2 rounded-full bg-surface-2 px-5 text-base font-semibold shadow-floating"
        @click="pickerOpen = !pickerOpen"
      >
        <TwIcon name="plus" :size="18" />
        {{ $t('dashboard.tile') }}
      </button>

      <button
        type="button"
        data-testid="dashboard-edit"
        :aria-pressed="editMode"
        :aria-label="editMode ? $t('dashboard.editDone') : $t('dashboard.edit')"
        class="flex size-12 items-center justify-center rounded-full shadow-floating transition-colors"
        :class="editMode ? 'bg-accent text-accent-ink' : 'bg-surface-2 text-ink-2'"
        @click="pencilTap"
      >
        <TwIcon :name="editMode ? 'check' : 'pencil'" :size="20" />
      </button>
    </div>

    <div
      v-if="pickerOpen"
      class="mb-2 flex flex-wrap gap-2 rounded-3xl bg-surface p-3 shadow-card"
    >
      <button
        v-for="entry in offer"
        :key="entry.type"
        type="button"
        data-testid="widget-offer"
        class="min-h-11 rounded-2xl bg-surface-2 px-4 text-base font-semibold"
        @click="createTile(entry.type)"
      >
        {{ entry.label }}
      </button>
    </div>

    <!-- No grid gap but padding per tile: otherwise the cell width while
         dragging would not match the computed one. -->
    <div
      ref="raster"
      data-testid="dashboard-grid"
      :class="narrow ? 'flex min-h-0 flex-1 flex-col overflow-y-auto' : 'grid min-h-0 flex-1 overflow-y-auto'"
      :style="narrow ? {} : {
        gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`,
        gridTemplateRows: `repeat(${rowCount}, minmax(${STACKED_ROW}px, 1fr))`,
        gridAutoRows: `minmax(${STACKED_ROW}px, 1fr)`,
      }"
    >
      <div
        v-for="tile in shown"
        :key="tile.id"
        data-testid="dashboard-tile"
        :data-type="tile.type"
        class="shrink-0 p-1"
        :class="drag?.id === tile.id ? 'relative z-10' : ''"
        :data-trading="drag?.trade?.id === tile.id ? true : undefined"
        :style="tileStyle(tile)"
      >
        <section
          class="relative flex h-full min-h-0 flex-col rounded-3xl bg-surface shadow-card transition-shadow"
          :class="[
            tile.type === 'photo' ? '' : 'p-4',
            drag?.trade?.id === tile.id ? 'ring-4 ring-accent/60' : '',
            editMode ? 'cursor-grab touch-none select-none ring-2 ring-accent/40' : '',
            drag?.id === tile.id ? (blocked ? 'cursor-grabbing opacity-80 shadow-floating ring-4 ring-danger' : 'cursor-grabbing shadow-floating ring-4 ring-accent') : '',
          ]"
          :data-blocked="drag?.id === tile.id ? blocked : undefined"
          @pointerdown="grab(tile, 'ziehen', $event)"
          @pointermove="move"
          @pointerup="release"
          @pointercancel="drag = null"
        >
          <!-- A photo runs edge to edge; its title would only cover the picture.
               The chips say what they are themselves. -->
          <h3 v-if="tile.type !== 'photo' && tile.type !== 'status'" class="tw-label mb-2 shrink-0 truncate text-sm font-bold text-ink-3">
            {{ label.get(tile.type) ?? tile.type }}
          </h3>

          <div class="min-h-0 flex-1 overflow-hidden" :class="tile.type === 'photo' ? 'rounded-3xl' : ''">
            <component
              :is="component(tile.type)"
              v-if="component(tile.type)"
              :time-zone="props.timeZone"
              :today="props.today"
              v-bind="tile.type === 'calendar' ? { settings: tile.settings } : tile.type === 'photo' ? { settings: tile.settings, tileId: tile.id, w: tile.w, h: tile.h } : {}"
              @pick-event="(event: AgendaEvent) => editMode || emit('pick-event', event)"
              @person="(id: number) => editMode || emit('show-tasks', id)"
              @area="(mode: Mode) => editMode || emit('show-area', mode)"
            />
            <WidgetGeneric v-else-if="generic.has(tile.type)" :tile-id="tile.id" :editing="editMode" :settings="tile.settings" />
          </div>

          <template v-if="editMode">
            <!--
              Covers the content while arranging: a calendar or list scrolls
              by itself, and a touch there panned it and cancelled the drag
              instead of moving the tile.
            -->
            <div data-testid="tile-grip" class="absolute inset-0 touch-none rounded-3xl" />

            <button
              type="button"
              data-testid="tile-remove"
              :aria-label="$t('common.removeNamed', { name: label.get(tile.type) ?? tile.type })"
              class="absolute -top-1 -right-1 flex size-9 items-center justify-center rounded-full bg-danger text-surface shadow-card"
              @pointerdown.stop
              @click="removeTile(tile.id)"
            >
              <TwIcon name="close" :size="16" />
            </button>

            <button
              v-if="tile.type === 'photo'"
              type="button"
              data-testid="tile-source"
              class="absolute top-2 right-10 flex min-h-11 max-w-[60%] items-center gap-1.5 truncate rounded-2xl bg-surface-2 px-3 text-sm font-semibold"
              @pointerdown.stop
              @click="photoTile = tile"
            >
              <TwIcon name="albums" :size="16" />
              {{ $t('dashboard.photoSource') }}
            </button>

            <button
              v-if="(fields.get(tile.type) ?? []).length > 0"
              type="button"
              data-testid="tile-settings-open"
              class="absolute top-2 right-10 flex min-h-11 items-center gap-1.5 rounded-2xl bg-surface-2 px-3 text-sm font-semibold"
              @pointerdown.stop
              @click="settingsTile = tile"
            >
              {{ $t('dashboard.tileSettings') }}
            </button>

            <button
              v-if="tile.type === 'calendar'"
              type="button"
              data-testid="tile-view"
              class="absolute top-2 right-10 flex min-h-11 items-center rounded-2xl bg-surface-2 px-3 text-sm font-semibold"
              @pointerdown.stop
              @click="toggleCalendarView(tile)"
            >
              {{ tile.settings.view === 'list' ? $t('dashboard.asGrid') : $t('dashboard.asList') }}
            </button>

            <!-- Big enough for a finger, not only a mouse pointer. -->
            <span
              data-testid="tile-handle"
              class="absolute -right-1 -bottom-1 flex size-12 cursor-se-resize touch-none items-center justify-center rounded-full bg-accent text-accent-ink shadow-card"
              @pointerdown.stop="grab(tile, 'groesse', $event)"
              @pointermove="move"
              @pointerup="release"
              @pointercancel="drag = null"
            >
              <TwIcon name="resize" :size="20" />
            </span>
          </template>
        </section>
      </div>

      <p v-if="narrow" data-testid="dashboard-arrange-hint" class="mt-3 mb-2 shrink-0 text-center text-sm text-ink-3">
        {{ $t('dashboard.arrangeWide') }}
      </p>
    </div>

    <!-- Outside the grid: there every row stretches to fill the screen. -->
    <p v-if="half && !narrow" data-testid="dashboard-arrange-hint" class="mt-2 shrink-0 text-center text-sm text-ink-3">
      {{ $t('dashboard.arrangeLandscape') }}
    </p>

    <PhotoTileSource
      v-if="photoTile"
      :settings="photoTile.settings"
      @close="photoTile = null"
      @save="choosePhotoSource"
    />

    <TileSettings
      v-if="settingsTile"
      :title="label.get(settingsTile.type) ?? settingsTile.type"
      :fields="fields.get(settingsTile.type) ?? []"
      :settings="settingsTile.settings"
      @close="settingsTile = null"
      @save="savePluginSettings"
    />

    <PinDialog
      v-if="pinOpen"
      @close="pinOpen = false"
      @unlocked="afterUnlock"
    />
  </div>
</template>
