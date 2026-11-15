<script setup lang="ts">
/**
 * Redeeming a reward: whose stars, how many they have, what is left.
 *
 * Replaces "tap your name, then tap the card twice": the tap on a card opens
 * this, the person is picked here (one tap), and the sum stands in front of
 * whoever confirms. Saving happens in the calling view, which also opens the
 * answer (`RedeemDone`) once the server has booked.
 */
import { affordable, shortfall } from '../../../shared/app/utils/points'
import type { RewardDto } from '../../../shared/app/utils/points'

interface Person {
  id: number
  name: string
  color: string | null
  avatar: string | null
  balance: number
}

interface Props {
  reward: RewardDto
  /** Whom the caller may redeem for; one person skips the choice. */
  people: Person[]
  /** Preselected, e.g. the child tapped above; ignored when not affordable. */
  initial: number | null
  /** True books at once (a parent's account), false asks the parents (wall, child). */
  atOnce: boolean
  busy: boolean
  error: string | null
}

const props: Props = defineProps<Props>()

const emit = defineEmits<{
  close: []
  redeem: [personId: number]
}>()

useEscape(() => emit('close'))

function firstChoice(): number | null {
  const able = props.people.filter(person => affordable(person.balance, props.reward.cost))
  const preset = able.find(person => person.id === props.initial)

  return preset?.id ?? (able.length === 1 ? able[0]!.id : null)
}

const chosen = ref<number | null>(firstChoice())
const person = computed(() => props.people.find(entry => entry.id === chosen.value) ?? null)
const left = computed(() => person.value === null ? 0 : person.value.balance - props.reward.cost)

function confirm(): void {
  if (person.value !== null && left.value >= 0 && !props.busy) {
    emit('redeem', person.value.id)
  }
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-center justify-center bg-scrim p-3 sm:p-6" @click.self="emit('close')">
    <form
      role="dialog"
      aria-modal="true"
      aria-labelledby="redeem-title"
      data-testid="redeem-dialog"
      class="tw-pop max-h-full w-full max-w-lg overflow-y-auto rounded-3xl bg-surface p-5 shadow-floating sm:p-8"
      @submit.prevent="confirm"
    >
      <div class="flex items-start justify-between gap-4">
        <h2 id="redeem-title" class="flex items-center gap-3 font-display text-2xl font-semibold sm:text-3xl">
          <span v-if="props.reward.icon" aria-hidden="true" class="text-4xl"><TwEmoji :icon="props.reward.icon" /></span>
          {{ $t('points.redeem.title', { reward: props.reward.name }) }}
        </h2>

        <button
          type="button"
          :aria-label="$t('common.close')"
          class="-mt-1 -mr-2 flex size-12 shrink-0 items-center justify-center rounded-full text-ink-2 transition-colors hover:bg-surface-2"
          @click="emit('close')"
        >
          <TwIcon name="close" />
        </button>
      </div>

      <p class="mt-2 flex items-center gap-1 text-lg font-semibold text-ink-2 tabular-nums">
        {{ $t('points.redeem.costs') }}
        <TwStar />
        {{ props.reward.cost }}
      </p>

      <fieldset class="mt-5">
        <legend class="text-base font-semibold text-ink-2">
          {{ $t('points.redeem.forWhom') }}
        </legend>

        <div class="mt-2 grid gap-2">
          <button
            v-for="entry in props.people"
            :key="entry.id"
            type="button"
            data-testid="redeem-person"
            :aria-pressed="chosen === entry.id"
            :disabled="!affordable(entry.balance, props.reward.cost)"
            class="flex min-h-14 items-center gap-3 rounded-2xl px-3 text-left text-lg font-semibold transition-colors disabled:opacity-45"
            :class="chosen === entry.id ? 'bg-accent-soft text-ink' : 'bg-surface-2 text-ink-2'"
            :style="chosen === entry.id ? { boxShadow: `inset 0 0 0 3px ${entry.color ?? '#8a8276'}` } : undefined"
            @click="chosen = entry.id"
          >
            <TwAvatar :name="entry.name" :color="entry.color" :avatar="entry.avatar" :size="36" />
            <span class="min-w-0 flex-1 truncate">{{ entry.name }}</span>
            <span
              v-if="!affordable(entry.balance, props.reward.cost)"
              class="text-sm font-semibold text-ink-3"
            >
              {{ $t('points.missing', { n: shortfall(entry.balance, props.reward.cost) }) }}
            </span>
            <span class="flex items-center gap-1 tabular-nums" :aria-label="$t('task.stars', entry.balance)">
              <TwStar />
              {{ entry.balance }}
            </span>
          </button>
        </div>
      </fieldset>

      <!-- The sum in front of the finger: has, costs, stays. -->
      <dl
        v-if="person"
        data-testid="redeem-sum"
        class="mt-5 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 rounded-2xl bg-surface-2 px-5 py-4 text-lg tabular-nums"
      >
        <dt class="text-ink-2">{{ $t('points.redeem.has', { name: person.name }) }}</dt>
        <dd class="flex items-center justify-end gap-1 font-semibold"><TwStar /> {{ person.balance }}</dd>
        <dt class="text-ink-2">{{ props.reward.name }}</dt>
        <dd class="flex items-center justify-end gap-1 font-semibold">−{{ props.reward.cost }}</dd>
        <dt class="border-t border-line pt-1 font-semibold">{{ $t('points.redeem.left') }}</dt>
        <dd data-testid="redeem-left" class="flex items-center justify-end gap-1 border-t border-line pt-1 font-display text-2xl font-semibold">
          <TwStar /> {{ left }}
        </dd>
      </dl>

      <p v-if="props.error" role="alert" class="mt-4 rounded-2xl bg-warning px-4 py-3 text-base font-medium text-warning-ink">
        {{ props.error }}
      </p>

      <div class="mt-6 flex justify-end gap-3">
        <button
          type="button"
          class="min-h-12 rounded-2xl px-5 text-lg font-semibold text-ink-2 transition-colors hover:bg-surface-2"
          @click="emit('close')"
        >
          {{ $t('common.cancel') }}
        </button>

        <button
          type="submit"
          data-testid="redeem-confirm"
          :disabled="person === null || props.busy"
          class="flex min-h-12 items-center gap-2 rounded-2xl bg-accent px-6 text-lg font-semibold text-accent-ink disabled:opacity-45"
        >
          <TwPending :busy="props.busy" />
          {{ props.atOnce ? $t('points.redeem.confirm') : $t('points.redeem.ask') }}
        </button>
      </div>
    </form>
  </div>
</template>
