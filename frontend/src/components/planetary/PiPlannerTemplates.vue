<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePlannerDisplay } from '@/composables/usePlannerDisplay'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { indexCatalog } from '@/utils/planetaryPlanner'
import {
  MAX_TEMPLATE_NAME_LENGTH,
  evaluateTemplate,
  isTemplateResolvable,
  type PlanTemplate,
} from '@/utils/planetaryTemplates'

type PendingAction = 'load' | 'delete'

const COMMUNITY_TEMPLATES_URL = 'https://github.com/DalShooth/EVE_PI_Templates/tree/main/PlanetaryInteractionTemplates'
const GRID_COLUMNS = 'grid min-w-[80rem] grid-cols-[minmax(10rem,1fr)_minmax(16rem,1.8fr)_5rem_6.5rem_6.5rem_6.5rem_6.5rem_6rem_14rem] items-center gap-3'
const FIGURES = [
  { testId: 'template-profit-day', key: 'profitPerDay' },
  { testId: 'template-profit-week', key: 'profitPerWeek' },
  { testId: 'template-profit-month', key: 'profitPerMonth' },
] as const

const { t, locale } = useI18n()
const store = usePlanetaryPlannerStore()
const { formatPlannerIsk, formatNumber } = usePlannerDisplay()

const templateName = ref('')
const pending = ref<{ action: PendingAction, templateId: string } | null>(null)

const canSave = computed(() => {
  const trimmedLength = templateName.value.trim().length
  return store.productions.length > 0 && trimmedLength > 0 && trimmedLength <= MAX_TEMPLATE_NAME_LENGTH
})

const entries = computed(() => {
  const catalog = store.catalog
  if (!catalog) return []
  const { typeById } = indexCatalog(catalog)

  return store.templates.map(template => {
    const evaluation = evaluateTemplate(template, catalog, store.account, store.extractionYieldOverrides)
    return {
      template,
      resolvable: isTemplateResolvable(template, catalog),
      evaluation,
      figures: FIGURES.map(figure => ({ testId: figure.testId, value: evaluation[figure.key] })),
      productions: template.productions.map(production => ({
        production,
        name: typeById.get(production.typeId)?.name ?? `#${production.typeId}`,
      })),
    }
  })
})

const pendingTemplate = computed(() => {
  const current = pending.value
  if (current === null) return null
  return store.templates.find(template => template.id === current.templateId) ?? null
})

function stateOf(value: number | null): 'known' | 'unknown' {
  return value === null ? 'unknown' : 'known'
}

function profitClass(value: number | null): string {
  if (value === null) return 'text-slate-500'
  return value < 0 ? 'text-red-400' : 'text-emerald-400'
}

function formatSavedDate(createdAt: string): string {
  return new Date(createdAt).toLocaleDateString(locale.value === 'fr' ? 'fr-FR' : 'en-US', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

async function onSave(): Promise<void> {
  if (!canSave.value) return
  if (await store.saveTemplate(templateName.value)) templateName.value = ''
}

function onLoad(template: PlanTemplate): void {
  if (store.productions.length === 0) {
    store.loadTemplateIntoPlan(template.id)
    return
  }
  pending.value = { action: 'load', templateId: template.id }
}

function onDelete(template: PlanTemplate): void {
  pending.value = { action: 'delete', templateId: template.id }
}

async function onAccept(): Promise<void> {
  const current = pending.value
  pending.value = null
  if (current === null) return

  if (current.action === 'load') store.loadTemplateIntoPlan(current.templateId)
  else await store.deleteTemplate(current.templateId)
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="planner-templates">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
      <div>
        <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.planner.templates.title') }}</h3>
        <p class="mt-1 max-w-3xl text-xs text-slate-500">{{ t('pi.planner.templates.subtitle') }}</p>
      </div>
      <a
        :href="COMMUNITY_TEMPLATES_URL"
        target="_blank"
        rel="noopener noreferrer"
        class="text-sm text-cyan-400 hover:text-cyan-300"
        data-testid="templates-community-link"
      >{{ t('pi.planner.templates.community') }} ↗</a>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
      <label class="block min-w-0 flex-1 basis-64">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.templates.nameLabel') }}</span>
        <input
          v-model="templateName"
          type="text"
          data-testid="template-name-input"
          :placeholder="t('pi.planner.templates.namePlaceholder')"
          class="mt-1 w-full rounded-md border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm text-white focus:border-cyan-500 focus:outline-hidden"
          @keydown.enter="onSave"
        />
      </label>
      <button
        type="button"
        data-testid="template-save"
        :disabled="!canSave"
        class="rounded-md border border-cyan-500/40 px-4 py-1.5 text-sm text-cyan-300 transition-colors hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:border-slate-700 disabled:text-slate-600 disabled:hover:bg-transparent"
        @click="onSave"
      >{{ t('pi.planner.templates.save') }}</button>
    </div>

    <p v-if="store.templates.length === 0" class="rounded-md border border-dashed border-slate-700 px-4 py-6 text-center text-sm text-slate-400" data-testid="templates-empty">{{ t('pi.planner.templates.empty') }}</p>

    <div v-else class="overflow-x-auto">
      <div :class="[GRID_COLUMNS, 'border-b border-slate-700 px-2 pb-2 text-xs uppercase tracking-wider text-slate-500']">
        <span>{{ t('pi.planner.templates.columns.name') }}</span>
        <span>{{ t('pi.planner.templates.columns.productions') }}</span>
        <span class="text-right">{{ t('pi.planner.templates.columns.planets') }}</span>
        <span class="text-right">{{ t('pi.planner.templates.columns.profitDay') }}</span>
        <span class="text-right">{{ t('pi.planner.templates.columns.profitWeek') }}</span>
        <span class="text-right">{{ t('pi.planner.templates.columns.profitMonth') }}</span>
        <span class="text-right">{{ t('pi.planner.templates.columns.purchases') }}</span>
        <span class="text-right">{{ t('pi.planner.templates.columns.date') }}</span>
        <span />
      </div>

      <template v-for="entry in entries" :key="entry.template.id">
        <div
          :class="[GRID_COLUMNS, 'border-b border-slate-800/70 px-2 py-2 text-sm']"
          data-testid="template-row"
          :data-template-id="entry.template.id"
        >
          <span class="min-w-0 truncate text-slate-100" data-testid="template-name" :title="entry.template.name">{{ entry.template.name }}</span>

          <div class="flex min-w-0 flex-col gap-0.5" data-testid="template-productions">
            <span v-for="(item, position) in entry.productions" :key="position" class="flex min-w-0 items-baseline gap-1 whitespace-nowrap font-mono-tech text-xs text-slate-300">
              <span class="min-w-0 truncate text-slate-100" :title="item.name">{{ item.name }}</span>
              <span class="shrink-0">× {{ formatNumber(item.production.targetPerDay, 2) }} {{ t('pi.planner.templates.perDay') }}</span>
            </span>
            <span
              v-if="!entry.resolvable"
              class="text-xs text-amber-400"
              data-testid="template-missing"
              :title="t('pi.planner.templates.missingHelp')"
            >{{ t('pi.planner.templates.missing') }}</span>
          </div>

          <span
            class="text-right font-mono-tech"
            :class="entry.evaluation.planets === null ? 'text-slate-500' : 'text-slate-100'"
            data-testid="template-planets"
            :data-state="stateOf(entry.evaluation.planets)"
            :data-incomplete="entry.evaluation.planetsIncomplete"
          >{{ entry.evaluation.planets === null ? t('pi.planner.unknown') : formatNumber(entry.evaluation.planets, 0) }}</span>

          <span
            v-for="figure in entry.figures"
            :key="figure.testId"
            class="text-right font-mono-tech"
            :class="profitClass(figure.value)"
            :data-testid="figure.testId"
            :data-state="stateOf(figure.value)"
            :data-incomplete="entry.evaluation.profitIncomplete"
          >{{ figure.value === null ? t('pi.planner.unknown') : formatPlannerIsk(figure.value) }}</span>

          <span
            class="text-right font-mono-tech"
            :class="entry.evaluation.weeklyPurchasesIsk === null ? 'text-slate-500' : 'text-slate-300'"
            data-testid="template-purchases"
            :data-state="stateOf(entry.evaluation.weeklyPurchasesIsk)"
            :data-incomplete="entry.evaluation.purchasesIncomplete"
          >{{ entry.evaluation.weeklyPurchasesIsk === null ? t('pi.planner.unknown') : formatPlannerIsk(entry.evaluation.weeklyPurchasesIsk) }}</span>

          <span class="text-right font-mono-tech text-xs text-slate-400" data-testid="template-date">{{ formatSavedDate(entry.template.createdAt) }}</span>

          <div class="flex justify-end gap-2">
            <button
              type="button"
              data-testid="template-load"
              :disabled="!entry.resolvable"
              class="rounded-md border border-cyan-500/40 px-2 py-1 text-xs text-cyan-300 transition-colors hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:border-slate-700 disabled:text-slate-600 disabled:hover:bg-transparent"
              @click="onLoad(entry.template)"
            >{{ t('pi.planner.templates.actions.load') }}</button>
            <button
              type="button"
              data-testid="template-add"
              :disabled="!entry.resolvable"
              class="rounded-md border border-cyan-500/40 px-2 py-1 text-xs text-cyan-300 transition-colors hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:border-slate-700 disabled:text-slate-600 disabled:hover:bg-transparent"
              @click="store.addTemplateToPlan(entry.template.id)"
            >{{ t('pi.planner.templates.actions.add') }}</button>
            <button
              type="button"
              data-testid="template-delete"
              class="rounded-md border border-red-500/40 px-2 py-1 text-xs text-red-300 transition-colors hover:bg-red-500/10"
              @click="onDelete(entry.template)"
            >{{ t('pi.planner.templates.actions.delete') }}</button>
          </div>
        </div>

        <div
          v-if="pending !== null && pendingTemplate !== null && pendingTemplate.id === entry.template.id"
          class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-500/30 bg-amber-500/5 px-3 py-2 text-sm text-amber-200"
          role="alert"
          data-testid="template-confirm"
          :data-action="pending.action"
          :data-template-id="entry.template.id"
        >
          <span class="min-w-0 truncate" :title="entry.template.name">{{ t(`pi.planner.templates.confirm.${pending.action}`, { name: entry.template.name }) }}</span>
          <span class="flex gap-2">
            <button
              type="button"
              data-testid="template-confirm-accept"
              class="rounded-md border border-amber-500/50 px-3 py-1 text-xs text-amber-200 transition-colors hover:bg-amber-500/10"
              @click="onAccept"
            >{{ t('pi.planner.templates.confirm.accept') }}</button>
            <button
              type="button"
              data-testid="template-confirm-cancel"
              class="rounded-md border border-slate-700 px-3 py-1 text-xs text-slate-300 transition-colors hover:border-slate-500"
              @click="pending = null"
            >{{ t('pi.planner.templates.confirm.cancel') }}</button>
          </span>
        </div>
      </template>
    </div>
  </section>
</template>
