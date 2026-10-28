<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useNavigationEntries, type NavigationEntry } from '@/composables/useNavigationEntries'
import { searchNavigationEntries } from '@/utils/commandPaletteSearch'

const props = defineProps<{
  show: boolean
  isAdmin: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

const { t } = useI18n()
const router = useRouter()
const { listNavigationEntries } = useNavigationEntries()

const listboxId = useId()
const navigationGroupLabelId = useId()
const searchInput = ref<HTMLInputElement | null>(null)
const query = ref('')
const activeIndex = ref(0)
const navigationEntries = ref<NavigationEntry[]>([])

let elementFocusedBeforeOpening: HTMLElement | null = null
let restoreFocusOnClose = true

const rows = computed(() => searchNavigationEntries(navigationEntries.value, query.value))
const hasRows = computed(() => rows.value.length > 0)

function optionId(index: number): string {
  return `${listboxId}-option-${index}`
}

const activeOptionId = computed(() => hasRows.value ? optionId(activeIndex.value) : undefined)

watch(query, () => {
  activeIndex.value = 0
})

watch(() => props.show, async (isShown) => {
  if (isShown) {
    elementFocusedBeforeOpening = document.activeElement instanceof HTMLElement ? document.activeElement : null
    restoreFocusOnClose = true
    query.value = ''
    activeIndex.value = 0
    navigationEntries.value = listNavigationEntries({ isAdmin: props.isAdmin })
    await nextTick()
    searchInput.value?.focus()
    return
  }
  await nextTick()
  if (restoreFocusOnClose) {
    elementFocusedBeforeOpening?.focus()
  }
  elementFocusedBeforeOpening = null
})

async function execute(entry: NavigationEntry): Promise<void> {
  restoreFocusOnClose = false
  emit('close')
  await router.push(entry.route)
}

function moveActiveRow(step: 1 | -1): void {
  if (!hasRows.value) {
    return
  }
  activeIndex.value = (activeIndex.value + step + rows.value.length) % rows.value.length
}

function onKeydown(event: KeyboardEvent): void {
  switch (event.key) {
    case 'Escape':
      event.preventDefault()
      event.stopPropagation()
      emit('close')
      break
    case 'Tab':
      event.preventDefault()
      event.stopPropagation()
      searchInput.value?.focus()
      break
    case 'ArrowDown':
      event.preventDefault()
      moveActiveRow(1)
      break
    case 'ArrowUp':
      event.preventDefault()
      moveActiveRow(-1)
      break
    case 'Enter':
      event.preventDefault()
      if (hasRows.value) {
        void execute(rows.value[activeIndex.value])
      }
      break
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-[60] flex items-start justify-center px-4 pt-[12vh]">
      <div
        data-testid="command-palette-backdrop"
        class="absolute inset-0 bg-slate-950/80"
        @click="emit('close')"
      ></div>
      <div
        role="dialog"
        aria-modal="true"
        :aria-label="t('commandPalette.title')"
        class="relative w-full max-w-xl bg-slate-900 border border-cyan-500/30 rounded-xl shadow-2xl shadow-cyan-500/10 overflow-hidden"
        @keydown="onKeydown"
      >
        <input
          ref="searchInput"
          v-model="query"
          type="text"
          role="combobox"
          autocomplete="off"
          spellcheck="false"
          aria-autocomplete="list"
          :aria-label="t('commandPalette.title')"
          :aria-expanded="hasRows"
          :aria-controls="listboxId"
          :aria-activedescendant="activeOptionId"
          :placeholder="t('commandPalette.placeholder')"
          class="w-full bg-transparent px-4 py-3 text-slate-100 placeholder-slate-500 border-b border-slate-800 focus:outline-hidden"
        />
        <div :id="listboxId" role="listbox" :aria-label="t('commandPalette.title')" class="max-h-[50vh] overflow-y-auto" @mousedown.prevent>
          <div v-if="hasRows" role="group" :aria-labelledby="navigationGroupLabelId" class="py-2">
            <div :id="navigationGroupLabelId" class="px-4 py-1 text-xs font-medium text-slate-500 uppercase tracking-wider">
              {{ t('commandPalette.groups.navigation') }}
            </div>
            <div
              v-for="(entry, index) in rows"
              :id="optionId(index)"
              :key="entry.route"
              role="option"
              :aria-selected="index === activeIndex"
              :class="[
                'px-4 py-2 cursor-pointer text-sm',
                index === activeIndex ? 'bg-cyan-500/20 text-cyan-100' : 'text-slate-300'
              ]"
              @mouseenter="activeIndex = index"
              @click="execute(entry)"
            >
              {{ entry.label }}
            </div>
          </div>
        </div>
        <p v-if="!hasRows" role="status" class="px-4 py-6 text-center text-sm text-slate-500">
          {{ t('commandPalette.noResults', { query }) }}
        </p>
      </div>
    </div>
  </Teleport>
</template>
