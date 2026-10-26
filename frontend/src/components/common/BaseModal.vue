<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

const props = withDefaults(defineProps<{
  show: boolean
  title: string
  panelClass?: string
}>(), {
  panelClass: 'max-w-lg',
})

const emit = defineEmits<{
  close: []
}>()

const titleId = useId()
const panel = ref<HTMLElement | null>(null)

let isOpen = false
let elementFocusedBeforeOpening: HTMLElement | null = null
let bodyOverflowBeforeOpening = ''

function focusableElements(): HTMLElement[] {
  if (!panel.value) {
    return []
  }
  return Array.from(panel.value.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR))
}

function trapTab(event: KeyboardEvent) {
  const focusable = focusableElements()
  if (focusable.length === 0) {
    event.preventDefault()
    panel.value?.focus()
    return
  }
  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  const active = document.activeElement
  const isOutside = !panel.value?.contains(active) || active === panel.value
  if (event.shiftKey && (active === first || isOutside)) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (active === last || isOutside)) {
    event.preventDefault()
    first.focus()
  }
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    emit('close')
  } else if (event.key === 'Tab') {
    trapTab(event)
  }
}

function open() {
  if (isOpen) {
    return
  }
  isOpen = true
  elementFocusedBeforeOpening = document.activeElement as HTMLElement | null
  bodyOverflowBeforeOpening = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  document.addEventListener('keydown', onKeydown)
  const [firstFocusable] = focusableElements()
  ;(firstFocusable ?? panel.value)?.focus()
}

function release() {
  if (!isOpen) {
    return
  }
  isOpen = false
  document.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = bodyOverflowBeforeOpening
}

function close() {
  release()
  elementFocusedBeforeOpening?.focus()
  elementFocusedBeforeOpening = null
}

onMounted(() => {
  if (props.show) {
    open()
  }
})

watch(() => props.show, (show) => {
  if (show) {
    open()
  } else {
    close()
  }
}, { flush: 'post' })

onBeforeUnmount(release)
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center">
      <div class="absolute inset-0 bg-slate-950/80" @click="emit('close')"></div>
      <div
        ref="panel"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        tabindex="-1"
        class="relative bg-slate-900 rounded-2xl shadow-2xl w-full mx-4 border focus:outline-none"
        :class="panelClass"
      >
        <slot name="title" :title-id="titleId">
          <h2 :id="titleId">{{ title }}</h2>
        </slot>
        <slot />
      </div>
    </div>
  </Teleport>
</template>
