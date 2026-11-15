<script setup lang="ts">
/**
 * The answer after redeeming: what was redeemed, for whom, what is left.
 * On the wall the stars are only asked for — the parents still decide, and
 * the sentence says so instead of pretending the stars are gone.
 */
interface Props {
  name: string
  reward: string
  icon: string | null
  cost: number
  left: number
  /** True when the stars are booked, false when the parents still decide. */
  booked: boolean
}

const props: Props = defineProps<Props>()

const emit = defineEmits<{ close: [] }>()

useEscape(() => emit('close'))
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-center justify-center bg-scrim p-3 sm:p-6" @click.self="emit('close')">
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="redeemed-title"
      data-testid="redeem-done"
      class="tw-pop w-full max-w-md rounded-3xl bg-surface p-6 text-center shadow-floating sm:p-8"
    >
      <span aria-hidden="true" class="tw-tada block text-6xl">
        <TwEmoji v-if="props.icon" :icon="props.icon" />
        <template v-else>🎉</template>
      </span>

      <h2 id="redeemed-title" class="mt-3 font-display text-2xl font-semibold sm:text-3xl">
        {{ props.booked ? $t('points.redeem.done') : $t('points.redeem.asked') }}
      </h2>

      <p class="mt-2 text-lg text-ink-2">
        {{ props.booked
          ? $t('points.redeem.doneText', { name: props.name, reward: props.reward, n: props.cost }, props.cost)
          : $t('points.redeem.askedText', { name: props.name, reward: props.reward, n: props.cost }, props.cost) }}
      </p>

      <p class="mt-5 flex items-center justify-center gap-2 rounded-2xl bg-surface-2 px-5 py-4 text-lg font-semibold tabular-nums">
        {{ props.booked ? $t('points.redeem.leftNow', { name: props.name }) : $t('points.redeem.leftAfter', { name: props.name }) }}
        <span data-testid="redeem-done-left" class="flex items-center gap-1 font-display text-2xl">
          <TwStar /> {{ props.left }}
        </span>
      </p>

      <button
        type="button"
        data-testid="redeem-done-close"
        class="mt-6 min-h-12 w-full rounded-2xl bg-accent px-6 text-lg font-semibold text-accent-ink"
        @click="emit('close')"
      >
        {{ $t('points.redeem.ok') }}
      </button>
    </div>
  </div>
</template>
