<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PiLayoutLibrary from '@/components/planetary/PiLayoutLibrary.vue'
import PiTierBadge from '@/components/planetary/PiTierBadge.vue'
import { useColonyLayoutExport } from '@/composables/useColonyLayoutExport'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryLayoutsStore, type ColonyLayout, type ColonyLayoutError } from '@/stores/planetaryLayouts'

type PendingAction = 'rename' | 'delete'

const MAX_LAYOUT_NAME_LENGTH = 100
const COMMUNITY_COLLECTIONS = [
  { id: 'dalshooth', url: 'https://github.com/DalShooth/EVE_PI_Templates' },
  { id: 'metalgear', url: 'https://github.com/MetalGear32/evepi' },
  { id: 'legion', url: 'https://github.com/TheLegi0n-NBI/PlayingWithPP' },
  { id: 'university', url: 'https://wiki.eveuniversity.org/PI_Templates' },
  { id: 'colonyBuilder', url: 'https://forums.eveonline.com/t/introducing-the-colony-builder-for-industrial-eve/486492' },
] as const

const { t, te } = useI18n()
const store = usePlanetaryLayoutsStore()
const { formatNumber } = useFormatters()
const { copied, failed, lastCopiedLayoutId, copyTemplate, downloadTemplate } = useColonyLayoutExport()

const importText = ref('')
const importName = ref('')
const importErrors = ref<ColonyLayoutError[]>([])
const isImporting = ref(false)
const pending = ref<{ action: PendingAction, layoutId: string } | null>(null)
const renameDraft = ref('')

const canImport = computed(() => importText.value.trim() !== '' && !isImporting.value && importName.value.length <= MAX_LAYOUT_NAME_LENGTH)

const renameIsValid = computed(() => {
  const length = renameDraft.value.trim().length
  return length > 0 && length <= MAX_LAYOUT_NAME_LENGTH
})

onMounted(() => store.load())

function pinCountOf(layout: ColonyLayout): number {
  const { buildings } = layout.summary
  return buildings.extractorControlUnits + buildings.processors + buildings.storages + buildings.launchpads
}

function planetLabelOf(layout: ColonyLayout): string {
  return layout.summary.planetTypeName ?? `#${layout.summary.planetTypeId}`
}

function nameOfType(typeId: number, typeName: string | null): string {
  return typeName ?? `#${typeId}`
}

function loadText(value: number | undefined): string {
  return value === undefined ? t('pi.layouts.card.unknown') : formatNumber(value, 0)
}

function errorMessageOf(error: ColonyLayoutError): string {
  const key = `pi.layouts.errors.${error.code}`
  return te(key) ? t(key) : error.code
}

async function onImport(): Promise<void> {
  if (!canImport.value) return

  isImporting.value = true
  const name = importName.value.trim()
  importErrors.value = await store.importLayout(importText.value, name === '' ? undefined : name)
  isImporting.value = false
  if (importErrors.value.length === 0) {
    importText.value = ''
    importName.value = ''
  }
}

async function onCopy(layout: ColonyLayout): Promise<void> {
  await copyTemplate(layout.id, layout.name, layout.gameTemplate)
}

function onDownload(layout: ColonyLayout): void {
  downloadTemplate(layout.name, layout.gameTemplate)
}

async function onTogglePublic(layout: ColonyLayout): Promise<void> {
  await store.setPublic(layout.id, !layout.public)
}

function onRename(layout: ColonyLayout): void {
  renameDraft.value = layout.name
  pending.value = { action: 'rename', layoutId: layout.id }
}

function onDelete(layout: ColonyLayout): void {
  pending.value = { action: 'delete', layoutId: layout.id }
}

async function onAcceptRename(layout: ColonyLayout): Promise<void> {
  if (!renameIsValid.value) return
  if (await store.rename(layout.id, renameDraft.value.trim())) pending.value = null
}

async function onAcceptDelete(layout: ColonyLayout): Promise<void> {
  pending.value = null
  await store.remove(layout.id)
}

function isPending(layout: ColonyLayout, action: PendingAction): boolean {
  return pending.value?.layoutId === layout.id && pending.value.action === action
}
</script>

<template>
  <div class="space-y-6" data-testid="pi-layouts-tab">
    <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="layout-import">
      <div class="mb-4">
        <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.layouts.import.title') }}</h3>
        <p class="mt-1 max-w-3xl text-xs text-slate-500">{{ t('pi.layouts.import.subtitle') }}</p>
      </div>

      <label class="block">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.layouts.import.textLabel') }}</span>
        <textarea
          v-model="importText"
          rows="6"
          spellcheck="false"
          data-testid="layout-import-text"
          :placeholder="t('pi.layouts.import.textPlaceholder')"
          class="mt-1 w-full rounded-md border border-slate-700 bg-slate-800 px-3 py-2 font-mono-tech text-xs text-white focus:border-cyan-500 focus:outline-hidden"
        />
      </label>

      <div class="mt-3 flex flex-wrap items-end gap-3">
        <label class="block min-w-0 flex-1 basis-64">
          <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.layouts.import.nameLabel') }}</span>
          <input
            v-model="importName"
            type="text"
            :maxlength="MAX_LAYOUT_NAME_LENGTH"
            data-testid="layout-import-name"
            :placeholder="t('pi.layouts.import.namePlaceholder')"
            class="mt-1 w-full rounded-md border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm text-white focus:border-cyan-500 focus:outline-hidden"
            @keydown.enter="onImport"
          />
        </label>
        <button
          type="button"
          data-testid="layout-import-submit"
          :disabled="!canImport"
          class="rounded-md border border-cyan-500/40 px-4 py-1.5 text-sm text-cyan-300 transition-colors hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:border-slate-700 disabled:text-slate-600 disabled:hover:bg-transparent"
          @click="onImport"
        >{{ t('pi.layouts.import.submit') }}</button>
      </div>

      <ul v-if="importErrors.length > 0" class="mt-4 space-y-1 rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300" role="alert" data-testid="layout-import-errors">
        <li
          v-for="(error, position) in importErrors"
          :key="position"
          class="flex min-w-0 items-baseline gap-2"
          data-testid="layout-import-error"
          :data-path="error.path"
          :data-code="error.code"
        >
          <span class="shrink-0 font-mono-tech text-xs text-red-200">{{ error.path }}</span>
          <span class="min-w-0">{{ errorMessageOf(error) }}</span>
        </li>
      </ul>
    </section>

    <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="layout-list">
      <div class="mb-4">
        <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.layouts.mine.title') }}</h3>
        <p class="mt-1 max-w-3xl text-xs text-slate-500">{{ t('pi.layouts.mine.subtitle') }}</p>
      </div>

      <p v-if="store.error" class="mb-3 rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300" role="alert" data-testid="layouts-error">{{ store.error }}</p>

      <p v-if="store.layouts.length === 0 && !store.isLoading && !store.error" class="rounded-md border border-dashed border-slate-700 px-4 py-6 text-center text-sm text-slate-400" data-testid="layouts-empty">{{ t('pi.layouts.mine.empty') }}</p>

      <div class="grid gap-3 lg:grid-cols-2">
        <article
          v-for="layout in store.layouts"
          :key="layout.id"
          class="min-w-0 rounded-lg border border-slate-700 bg-slate-800/60 p-4"
          data-testid="layout-card"
          :data-layout-id="layout.id"
        >
          <div class="flex min-w-0 items-baseline justify-between gap-3">
            <h4 class="min-w-0 truncate text-base font-semibold text-slate-100" data-testid="layout-name" :title="layout.name">{{ layout.name }}</h4>
            <span class="shrink-0 font-mono-tech text-xs text-cyan-300" data-testid="layout-planet">{{ planetLabelOf(layout) }}</span>
          </div>
          <p v-if="layout.public" class="mt-1 text-xs uppercase tracking-wider text-emerald-300" data-testid="layout-public-badge">{{ t('pi.layouts.card.public') }}</p>

          <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs sm:grid-cols-4">
            <div>
              <dt class="uppercase tracking-wider text-slate-500">{{ t('pi.layouts.card.level') }}</dt>
              <dd class="font-mono-tech text-sm text-slate-100" data-testid="layout-level">{{ layout.summary.commandCenterLevel }}</dd>
            </div>
            <div>
              <dt class="uppercase tracking-wider text-slate-500">{{ t('pi.layouts.card.pins') }}</dt>
              <dd class="font-mono-tech text-sm text-slate-100" data-testid="layout-pins" :title="t('pi.layouts.card.pinsDetail', layout.summary.buildings)">{{ pinCountOf(layout) }}</dd>
            </div>
            <div>
              <dt class="uppercase tracking-wider text-slate-500">{{ t('pi.layouts.card.cpu') }}</dt>
              <dd
                class="font-mono-tech text-sm"
                :class="layout.summary.load === null ? 'text-slate-500' : 'text-slate-100'"
                data-testid="layout-cpu"
                :title="layout.summary.load === null ? t('pi.layouts.card.loadUnknown') : undefined"
              >{{ loadText(layout.summary.load?.cpu) }}</dd>
            </div>
            <div>
              <dt class="uppercase tracking-wider text-slate-500">{{ t('pi.layouts.card.powergrid') }}</dt>
              <dd
                class="font-mono-tech text-sm"
                :class="layout.summary.load === null ? 'text-slate-500' : 'text-slate-100'"
                data-testid="layout-powergrid"
                :title="layout.summary.load === null ? t('pi.layouts.card.loadUnknown') : undefined"
              >{{ loadText(layout.summary.load?.powergrid) }}</dd>
            </div>
          </dl>

          <div class="mt-3 flex min-w-0 flex-col gap-1" data-testid="layout-products">
            <span
              v-for="product in layout.summary.products"
              :key="product.typeId"
              class="flex min-w-0 items-center gap-2 text-sm text-slate-100"
            >
              <PiTierBadge :tier="product.tier" />
              <span class="min-w-0 truncate" :title="nameOfType(product.typeId, product.typeName)">{{ nameOfType(product.typeId, product.typeName) }}</span>
            </span>
            <span v-if="layout.summary.products.length === 0" class="text-xs text-slate-500">{{ t('pi.layouts.card.noProduct') }}</span>
            <span v-if="layout.summary.extracted.length > 0" class="min-w-0 truncate text-xs text-slate-400" :title="layout.summary.extracted.map(item => nameOfType(item.typeId, item.typeName)).join(', ')">
              {{ t('pi.layouts.card.extracted', { names: layout.summary.extracted.map(item => nameOfType(item.typeId, item.typeName)).join(', ') }) }}
            </span>
          </div>

          <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
            <span v-if="copied && lastCopiedLayoutId === layout.id" class="mr-auto text-xs text-emerald-300" role="status">{{ t('pi.layouts.card.copied') }}</span>
            <span v-else-if="failed && lastCopiedLayoutId === layout.id" class="mr-auto text-xs text-amber-300" role="alert">{{ t('pi.layouts.card.copyFailed') }}</span>
            <button
              type="button"
              data-testid="layout-copy"
              class="rounded-md border border-cyan-500/40 px-2 py-1 text-xs text-cyan-300 transition-colors hover:bg-cyan-500/10"
              @click="onCopy(layout)"
            >{{ t('pi.layouts.card.copy') }}</button>
            <button
              type="button"
              data-testid="layout-download"
              class="rounded-md border border-cyan-500/40 px-2 py-1 text-xs text-cyan-300 transition-colors hover:bg-cyan-500/10"
              @click="onDownload(layout)"
            >{{ t('pi.layouts.card.download') }}</button>
            <button
              type="button"
              data-testid="layout-publish-toggle"
              class="rounded-md border border-emerald-500/40 px-2 py-1 text-xs text-emerald-300 transition-colors hover:bg-emerald-500/10"
              @click="onTogglePublic(layout)"
            >{{ layout.public ? t('pi.layouts.card.unpublish') : t('pi.layouts.card.publish') }}</button>
            <button
              type="button"
              data-testid="layout-rename"
              class="rounded-md border border-slate-600 px-2 py-1 text-xs text-slate-300 transition-colors hover:border-slate-400"
              @click="onRename(layout)"
            >{{ t('pi.layouts.card.rename') }}</button>
            <button
              type="button"
              data-testid="layout-delete"
              class="rounded-md border border-red-500/40 px-2 py-1 text-xs text-red-300 transition-colors hover:bg-red-500/10"
              @click="onDelete(layout)"
            >{{ t('pi.layouts.card.delete') }}</button>
          </div>

          <div v-if="isPending(layout, 'rename')" class="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-700 pt-3" data-testid="layout-rename-form">
            <label class="block min-w-0 flex-1 basis-48">
              <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.layouts.card.renameLabel') }}</span>
              <input
                v-model="renameDraft"
                type="text"
                :maxlength="MAX_LAYOUT_NAME_LENGTH"
                data-testid="layout-rename-input"
                class="mt-1 w-full rounded-md border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm text-white focus:border-cyan-500 focus:outline-hidden"
                @keydown.enter="onAcceptRename(layout)"
              />
            </label>
            <button
              type="button"
              data-testid="layout-rename-accept"
              :disabled="!renameIsValid"
              class="rounded-md border border-cyan-500/40 px-3 py-1 text-xs text-cyan-300 transition-colors hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:border-slate-700 disabled:text-slate-600 disabled:hover:bg-transparent"
              @click="onAcceptRename(layout)"
            >{{ t('pi.layouts.card.confirm') }}</button>
            <button
              type="button"
              data-testid="layout-rename-cancel"
              class="rounded-md border border-slate-700 px-3 py-1 text-xs text-slate-300 transition-colors hover:border-slate-500"
              @click="pending = null"
            >{{ t('pi.layouts.card.cancel') }}</button>
          </div>

          <div
            v-if="isPending(layout, 'delete')"
            class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-amber-500/30 bg-amber-500/5 px-3 py-2 text-sm text-amber-200"
            role="alert"
            data-testid="layout-delete-confirm"
          >
            <span class="min-w-0 truncate" :title="layout.name">{{ t('pi.layouts.card.deleteConfirm', { name: layout.name }) }}</span>
            <span class="flex gap-2">
              <button
                type="button"
                data-testid="layout-delete-accept"
                class="rounded-md border border-amber-500/50 px-3 py-1 text-xs text-amber-200 transition-colors hover:bg-amber-500/10"
                @click="onAcceptDelete(layout)"
              >{{ t('pi.layouts.card.confirm') }}</button>
              <button
                type="button"
                data-testid="layout-delete-cancel"
                class="rounded-md border border-slate-700 px-3 py-1 text-xs text-slate-300 transition-colors hover:border-slate-500"
                @click="pending = null"
              >{{ t('pi.layouts.card.cancel') }}</button>
            </span>
          </div>
        </article>
      </div>
    </section>

    <PiLayoutLibrary />

    <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="layout-community">
      <div class="mb-4">
        <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.layouts.community.title') }}</h3>
        <p class="mt-1 max-w-3xl text-xs text-slate-500">{{ t('pi.layouts.community.subtitle') }}</p>
      </div>
      <ul class="grid gap-3 md:grid-cols-2">
        <li v-for="collection in COMMUNITY_COLLECTIONS" :key="collection.id" class="min-w-0">
          <a
            :href="collection.url"
            target="_blank"
            rel="noopener noreferrer"
            class="block min-w-0 rounded-lg border border-slate-700 bg-slate-800/60 px-4 py-3 transition-colors hover:border-cyan-500/50"
            data-testid="layout-community-link"
          >
            <span class="block truncate text-sm font-semibold text-cyan-300">{{ t(`pi.layouts.community.links.${collection.id}.name`) }} ↗</span>
            <span class="mt-1 block text-xs text-slate-400">{{ t(`pi.layouts.community.links.${collection.id}.description`) }}</span>
          </a>
        </li>
      </ul>
    </section>
  </div>
</template>
