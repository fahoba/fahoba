<script setup lang="ts">
/*
 * A progress ring around something small: an avatar, a number. Reads from
 * across the hallway where a thin bar does not — the arc's length is the
 * share, its colour whose it is.
 */
interface Props {
  /** Share done, 0 to 1. */
  value: number
  /** Outer size in pixels. */
  size?: number
  stroke?: number
  /** Any CSS colour; the accent by default. */
  color?: string
}

const props: Props = defineProps<Props>()

const size = computed(() => props.size ?? 44)
const stroke = computed(() => props.stroke ?? 4)
const radius = computed(() => (size.value - stroke.value) / 2)
const length = computed(() => 2 * Math.PI * radius.value)
const share = computed(() => Math.min(1, Math.max(0, Number.isFinite(props.value) ? props.value : 0)))
</script>

<template>
  <span class="relative inline-flex shrink-0 items-center justify-center" :style="{ width: `${size}px`, height: `${size}px` }">
    <svg :width="size" :height="size" class="absolute inset-0 -rotate-90" aria-hidden="true">
      <circle :cx="size / 2" :cy="size / 2" :r="radius" fill="none" stroke="var(--line)" :stroke-width="stroke" />
      <circle
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        fill="none"
        :stroke="props.color ?? 'var(--accent)'"
        :stroke-width="stroke"
        stroke-linecap="round"
        :stroke-dasharray="length"
        :stroke-dashoffset="length * (1 - share)"
        :opacity="share > 0 ? 1 : 0"
        class="transition-[stroke-dashoffset] duration-500"
      />
    </svg>
    <slot />
  </span>
</template>
