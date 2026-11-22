<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useCompanionCountdown } from '@/composables/useCompanionCountdown'
import { useFormatters } from '@/composables/useFormatters'
import type { ProjectTransportLegLine } from '@/stores/group-industry/types'
import CompanionHereBadge from './CompanionHereBadge.vue'
import type { CompanionCarry, CompanionLeg, CompanionTasks } from './companionTasks'

const NUMBER_DECIMALS = 0
const VOLUME_DECIMALS = 2

const props = defineProps<{
  tasks: CompanionTasks
}>()

const emit = defineEmits<{
  arrive: [transferId: string]
  'declare-leg': [key: string]
}>()

const { t } = useI18n()
const { countdownText } = useCompanionCountdown()
const { formatNumber } = useFormatters()

function systemsText(leg: CompanionLeg): string | null {
  const systems = [leg.fromSystem, leg.toSystem].filter((system): system is string => system !== null)
  return systems.length > 0 && (leg.fromSystem !== leg.from || leg.toSystem !== leg.to) ? systems.join(' \u2192 ') : null
}

function productionRemainingMs(line: ProjectTransportLegLine): number | null {
  if (line.readyAt === null) return null
  const readyAt = Date.parse(line.readyAt)
  return Number.isNaN(readyAt) ? null : readyAt - props.tasks.now
}

function productionText(line: ProjectTransportLegLine): string | null {
  const remainingMs = productionRemainingMs(line)
  if (remainingMs === null) return null
  return remainingMs <= 0 ? t('groupIndustry.companion.transport.readyNow') : countdownText(remainingMs)
}

function carryName(carry: CompanionCarry): string {
  return carry.lines.at(0)?.typeName ?? t('common.unknownName')
}

function carryEtaText(carry: CompanionCarry): string | null {
  if (carry.state !== 'in_transit') return null
  const arrival = Date.parse(carry.expectedArrivalAt)
  if (Number.isNaN(arrival)) return null
  const remainingMs = arrival - props.tasks.now
  return remainingMs <= 0 ? t('groupIndustry.companion.carry.due') : countdownText(remainingMs)
}
</script>

<template>
  <div data-testid="companion-pane-transport" class="flex flex-col gap-2">
    <p
      v-if="tasks.legsUnknown"
      data-testid="companion-legs-unknown"
      data-unknown="true"
      class="text-xs italic text-slate-500"
    >{{ t('groupIndustry.companion.transport.unknown') }}</p>
    <p v-else-if="tasks.legs.length === 0" data-testid="companion-legs-empty" class="text-xs text-slate-500">{{ t('groupIndustry.companion.transport.empty') }}</p>
    <p
      v-if="tasks.legsUnreadableCount > 0"
      data-testid="companion-legs-unreadable"
      class="text-xs text-amber-300"
    >{{ t('groupIndustry.companion.transport.unreadable', { count: tasks.legsUnreadableCount }, tasks.legsUnreadableCount) }}</p>
    <section v-if="tasks.legs.length > 0" data-testid="companion-legs" class="flex flex-col gap-1">
      <h2 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-[0.14em] text-cyan-300">
        {{ t('groupIndustry.companion.transport.title') }}
        <span class="font-mono font-normal text-slate-400">{{ tasks.legs.length }}</span>
      </h2>
      <ul class="flex flex-col gap-1">
        <li
          v-for="leg in tasks.legs"
          :key="leg.key"
          data-testid="companion-leg"
          :data-here="leg.here"
          :data-access="leg.accessUnknown ? 'unknown' : 'granted'"
          class="flex flex-col gap-1 border-l-2 border-cyan-400 bg-gradient-to-r from-cyan-400/10 to-white/[0.02] py-1 pr-1.5 pl-2"
        >
          <div class="flex items-center gap-2">
            <div class="min-w-0 flex-1">
              <p data-testid="companion-leg-route" class="flex items-center gap-1 text-sm font-semibold text-slate-100">
                <span v-if="leg.from !== null" class="min-w-0 truncate" :title="leg.from">{{ leg.from }}</span>
                <span v-else data-unknown="true" class="font-sans text-xs font-normal italic text-slate-500">{{ t('common.unknownValue') }}</span>
                <span class="flex-shrink-0">&rarr;</span>
                <span v-if="leg.to !== null" class="min-w-0 truncate" :title="leg.to">{{ leg.to }}</span>
                <span v-else data-unknown="true" class="font-sans text-xs font-normal italic text-slate-500">{{ t('common.unknownValue') }}</span>
                <CompanionHereBadge v-if="leg.here === true" />
              </p>
              <p v-if="systemsText(leg) !== null" class="truncate text-xs text-slate-400" :title="systemsText(leg) ?? undefined">{{ systemsText(leg) }}</p>
              <p class="flex items-center gap-1.5 truncate font-mono text-xs text-slate-400" style="font-variant-numeric: tabular-nums;">
                <span v-if="leg.volume !== null">{{ formatNumber(leg.volume, VOLUME_DECIMALS) }} m&sup3;</span>
                <span v-else data-unknown="true" class="font-sans italic text-slate-500">{{ t('common.unknownValue') }}</span>
                <span v-if="leg.accessUnknown" data-testid="companion-leg-access-unknown" class="font-sans italic text-amber-300">{{ t('groupIndustry.companion.transport.accessUnknown') }}</span>
              </p>
            </div>
            <span
              v-if="leg.declared"
              data-testid="companion-leg-declared"
              class="flex-shrink-0 border border-slate-700 px-1.5 py-0.5 text-xs uppercase tracking-wider text-slate-300"
            >{{ t('groupIndustry.companion.transport.declared') }}</span>
            <button
              v-else-if="leg.canDeclare"
              type="button"
              data-testid="companion-leg-declare"
              class="flex-shrink-0 border border-emerald-400 bg-emerald-400 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide text-emerald-950"
              @click="emit('declare-leg', leg.key)"
            >{{ t('groupIndustry.companion.transport.declare') }}</button>
          </div>
          <ul class="flex flex-col gap-0.5">
            <li
              v-for="(line, index) in leg.lines"
              :key="`${line.typeId}-${line.state}-${line.source}-${index}`"
              data-testid="companion-leg-line"
              :data-state="line.state"
              class="flex items-center gap-2 text-xs text-slate-300"
            >
              <span class="min-w-0 flex-1 truncate font-semibold text-slate-100" :title="line.typeName">{{ line.typeName }}</span>
              <span class="flex-shrink-0 font-mono" style="font-variant-numeric: tabular-nums;">
                <template v-if="line.quantity !== null">&times;{{ formatNumber(line.quantity, NUMBER_DECIMALS) }}</template>
                <span v-else data-unknown="true" class="font-sans italic text-slate-500">{{ t('common.unknownValue') }}</span>
              </span>
              <span class="flex flex-shrink-0 items-center gap-1 uppercase tracking-wider text-slate-400">
                {{ t(`groupIndustry.companion.transport.states.${line.state}`) }}
                <template v-if="line.state === 'in_production'">
                  <span v-if="productionText(line) !== null" class="font-mono normal-case">&middot; {{ productionText(line) }}</span>
                  <span v-else data-unknown="true" class="font-sans normal-case italic text-slate-500">{{ t('common.unknownValue') }}</span>
                </template>
              </span>
              <span class="flex-shrink-0 font-mono text-slate-400" style="font-variant-numeric: tabular-nums;">
                <template v-if="line.volume !== null">{{ formatNumber(line.volume, VOLUME_DECIMALS) }} m&sup3;</template>
                <span v-else data-unknown="true" class="font-sans italic text-slate-500">{{ t('common.unknownValue') }}</span>
              </span>
            </li>
          </ul>
        </li>
      </ul>
    </section>
    <p
      v-if="tasks.carriesUnknown"
      data-testid="companion-carries-unknown"
      data-unknown="true"
      class="text-xs italic text-slate-500"
    >{{ t('groupIndustry.companion.carry.unknown') }}</p>
    <section v-if="tasks.carries.length > 0" data-testid="companion-carries" class="flex flex-col gap-1">
      <h2 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-[0.14em] text-emerald-300">
        {{ t('groupIndustry.companion.carry.title') }}
        <span class="font-mono font-normal text-slate-400">{{ tasks.carries.length }}</span>
      </h2>
      <ul class="flex flex-col gap-1">
        <li
          v-for="carry in tasks.carries"
          :key="carry.id"
          data-testid="companion-carry"
          :data-state="carry.state"
          class="flex items-center gap-2 border-l-2 border-emerald-400 bg-gradient-to-r from-emerald-400/10 to-white/[0.02] py-1 pr-1.5 pl-2"
        >
          <div class="min-w-0 flex-1">
            <p class="flex items-center gap-1">
              <span class="min-w-0 truncate text-sm font-semibold text-slate-100" :title="carryName(carry)">{{ carryName(carry) }}</span>
              <span v-if="carry.lines.length > 1" class="flex-shrink-0 font-mono text-xs text-slate-400">+{{ carry.lines.length - 1 }}</span>
              <CompanionHereBadge v-if="carry.here === true" />
            </p>
            <p data-testid="companion-carry-route" class="truncate text-xs text-slate-400">
              <span v-if="carry.from !== null" :title="carry.from">{{ carry.from }}</span>
              <span v-else data-unknown="true" class="italic text-slate-500">{{ t('common.unknownValue') }}</span>
              &rarr;
              <span v-if="carry.to !== null" :title="carry.to">{{ carry.to }}</span>
              <span v-else data-unknown="true" class="italic text-slate-500">{{ t('common.unknownValue') }}</span>
            </p>
            <p class="truncate font-mono text-xs text-slate-400" style="font-variant-numeric: tabular-nums;">
              <span v-if="carry.volume !== null">{{ formatNumber(carry.volume, NUMBER_DECIMALS) }} m&sup3;</span>
              <span v-else data-unknown="true" class="font-sans italic text-slate-500">{{ t('common.unknownValue') }}</span>
              <span v-if="carryEtaText(carry) !== null"> &middot; {{ carryEtaText(carry) }}</span>
            </p>
          </div>
          <span class="flex-shrink-0 border border-slate-700 px-1.5 py-0.5 text-xs uppercase tracking-wider text-slate-300">{{ t(`groupIndustry.companion.carry.states.${carry.state}`) }}</span>
          <button
            v-if="carry.state === 'in_transit'"
            type="button"
            data-testid="companion-carry-arrive"
            class="flex-shrink-0 border border-emerald-400 bg-emerald-400 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide text-emerald-950"
            @click="emit('arrive', carry.id)"
          >{{ t('groupIndustry.companion.carry.arrived') }}</button>
        </li>
      </ul>
    </section>
  </div>
</template>
