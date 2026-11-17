<script setup lang="ts">
import { areaEmoji } from '../../../../shared/app/utils/areaEmoji'
import type { AgendaArea, Mode } from '~/utils/areas'

/*
 * The household at a glance, as a row of chips: what is open, what is on
 * today, what is missing at home, what is for dinner. Each chip leads to its
 * area. Only areas the household has switched on appear.
 */
const props = defineProps<{ today: string }>()
const emit = defineEmits<{ area: [Mode] }>()

const { t } = useI18n()
const { agenda } = useAgenda()
const { occurrences, load: loadTasks } = useTasks()
const { lists, load: loadLists } = useLists()
const { meals, load: loadMeals } = useMeals()

const areas = computed(() => new Map((agenda.value?.household.areas ?? []).map(area => [area.feature, area])))

onMounted(() => {
  if (areas.value.has('tasks') && occurrences.value.length === 0) {
    loadTasks(props.today, props.today)
  }

  if (areas.value.has('lists') && lists.value.length === 0) {
    loadLists()
  }

  if (areas.value.has('meals') && meals.value.length === 0) {
    loadMeals(props.today, props.today)
  }
})

interface Chip {
  mode: Mode
  icon: AgendaArea['icon']
  label: string
  detail: string
}

const chips = computed<Chip[]>(() => {
  const result: Chip[] = []
  const add = (feature: string, detail: string): void => {
    const area = areas.value.get(feature)

    if (area) {
      result.push({ mode: area.mode, icon: area.icon, label: area.label, detail })
    }
  }

  const open = occurrences.value.filter(entry => entry.date === props.today && !entry.done).length
  add('tasks', open === 0 ? t('status.allDone') : t('tasks.openCount', { n: open }))

  const events = (agenda.value?.events ?? [])
    .filter(event => event.starts_at.slice(0, 10) <= props.today && event.ends_at.slice(0, 10) >= props.today).length
  add('calendar', t('status.eventsToday', events))

  const missing = lists.value.reduce((sum, list) => sum + list.open_count, 0)
  add('lists', missing === 0 ? t('status.nothingMissing') : t('tasks.openCount', { n: missing }))

  const meal = meals.value.find(entry => entry.date === props.today)
  add('meals', meal ? meal.title : t('status.nothingPlanned'))

  return result
})
</script>

<template>
  <div class="flex h-full items-center gap-2 overflow-x-auto">
    <button
      v-for="chip in chips"
      :key="chip.mode"
      type="button"
      data-testid="status-chip"
      :data-value="chip.mode"
      class="tw-press flex min-h-14 max-w-64 shrink-0 items-center gap-2.5 rounded-full bg-surface-2 py-1.5 pr-5 pl-1.5 text-left transition-colors hover:bg-surface-3"
      @click="emit('area', chip.mode)"
    >
      <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface text-[22px] leading-none" aria-hidden="true">
        <TwEmoji v-if="areaEmoji(chip.icon)" :icon="areaEmoji(chip.icon) ?? ''" colored />
        <TwIcon v-else :name="chip.icon" :size="22" />
      </span>
      <span class="min-w-0">
        <span class="block truncate text-base leading-tight font-semibold">{{ chip.label }}</span>
        <span class="block truncate text-sm leading-tight text-ink-2">{{ chip.detail }}</span>
      </span>
    </button>
  </div>
</template>
