<script setup lang="ts">
import { computed } from 'vue'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupInventionStore } from '@/stores/group-industry/invention'
import { useGroupLineBlueprintsStore } from '@/stores/group-industry/lineBlueprints'
import { useGroupResearchStore } from '@/stores/group-industry/research'
import type { BomItem, LineBlueprintState, ResearchVerdict } from '@/stores/group-industry/types'
import { blueprintsOfLine, inventionProductOf, researchRowOf, type DetailBlockKind } from './projectFlowCardDetail'
import { useFlowDetailFormat } from './useFlowDetailFormat'

const props = defineProps<{
  kind: DetailBlockKind
  line: BomItem
}>()

const PLACE_LABEL_KEYS: Record<LineBlueprintState, string> = {
  here: 'places.here',
  elsewhere: 'places.elsewhere',
  unknown: 'unknown',
}
const VERDICT_LABEL_KEYS: Record<ResearchVerdict, string> = {
  worth: 'verdicts.worth',
  not_worth: 'verdicts.notWorth',
  unknown: 'unknown',
}

const { formatNumber } = useFormatters()
const { detailText, numberOrUnknown, iskOrUnknown, textOrUnknown, probabilityOrUnknown } = useFlowDetailFormat()
const lineBlueprintsStore = useGroupLineBlueprintsStore()
const researchStore = useGroupResearchStore()
const inventionStore = useGroupInventionStore()

const lineBlueprints = computed(() => blueprintsOfLine(lineBlueprintsStore.blueprints, props.line.id))
const researchRow = computed(() => researchRowOf(researchStore.rows, props.line.typeId))
const inventionProduct = computed(() => (inventionStore.products === null ? undefined : inventionProductOf(inventionStore.products, props.line.typeId)))

function efficiencies(materialEfficiency: number | null, timeEfficiency: number | null): string {
  return `${numberOrUnknown(materialEfficiency)} / ${numberOrUnknown(timeEfficiency)}`
}
</script>

<template>
  <div v-if="kind === 'blueprints'">
    <ul v-if="lineBlueprints.length > 0" class="space-y-0.5">
      <li v-for="blueprint in lineBlueprints" :key="blueprint.id" data-testid="flow-detail-blueprint" class="flex flex-wrap gap-x-2">
        <span>{{ blueprint.kind === null ? detailText('unknown') : detailText(`kinds.${blueprint.kind}`) }}</span>
        <span class="tabular-nums">{{ detailText('phrases.runs', { runs: numberOrUnknown(blueprint.runs) }) }}</span>
        <span class="tabular-nums">{{ detailText('fields.meTe') }} {{ efficiencies(blueprint.materialEfficiency, blueprint.timeEfficiency) }}</span>
        <span>{{ detailText(PLACE_LABEL_KEYS[blueprint.state]) }}</span>
        <span class="text-slate-500">{{ textOrUnknown(blueprint.blueprintPlace.name) }}</span>
      </li>
    </ul>
    <p v-else class="text-slate-400">{{ detailText('phrases.noBlueprint') }}</p>
  </div>

  <div v-else-if="kind === 'research'">
    <dl v-if="researchRow !== undefined" class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
      <dt class="text-slate-500">{{ detailText('fields.currentLevel') }}</dt>
      <dd class="tabular-nums">{{ numberOrUnknown(researchRow.currentLevel) }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.targetLevel') }}</dt>
      <dd class="tabular-nums">{{ numberOrUnknown(researchRow.targetLevel) }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.verdict') }}</dt>
      <dd>{{ detailText(VERDICT_LABEL_KEYS[researchRow.verdict]) }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.net') }}</dt>
      <dd class="tabular-nums">{{ iskOrUnknown(researchRow.net) }}</dd>
    </dl>
    <p v-else class="text-slate-400">{{ detailText('phrases.noResearch') }}</p>
  </div>

  <div v-else-if="kind === 'decryptors'">
    <table v-if="inventionProduct !== undefined" class="w-full border-collapse text-left">
      <thead>
        <tr class="text-[10.5px] uppercase tracking-wider text-slate-500">
          <th class="py-0.5 pr-2 font-semibold">{{ detailText('fields.decryptor') }}</th>
          <th class="py-0.5 pr-2 text-right font-semibold">{{ detailText('fields.chance') }}</th>
          <th class="py-0.5 pr-2 text-right font-semibold">{{ detailText('fields.runsColumn') }}</th>
          <th class="py-0.5 pr-2 text-right font-semibold">{{ detailText('fields.meTe') }}</th>
          <th class="py-0.5 pr-2 text-right font-semibold">{{ detailText('fields.attempts') }}</th>
          <th class="py-0.5 text-right font-semibold">{{ detailText('fields.costPerUnit') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="option in inventionProduct.invention.options" :key="option.decryptorTypeId ?? 'none'" data-testid="flow-detail-decryptor" class="border-t border-slate-800/60">
          <td class="py-0.5 pr-2">{{ option.decryptorName }}</td>
          <td class="py-0.5 pr-2 text-right tabular-nums">{{ probabilityOrUnknown(option.probability) }}</td>
          <td class="py-0.5 pr-2 text-right tabular-nums">{{ formatNumber(option.runs, 0) }}</td>
          <td class="py-0.5 pr-2 text-right tabular-nums">{{ efficiencies(option.me, option.te) }}</td>
          <td class="py-0.5 pr-2 text-right tabular-nums">{{ numberOrUnknown(option.expectedAttempts) }}</td>
          <td class="py-0.5 text-right tabular-nums">{{ iskOrUnknown(option.costPerUnit) }}</td>
        </tr>
      </tbody>
    </table>
    <p v-else class="text-slate-400">{{ detailText('phrases.noInvention') }}</p>
  </div>
</template>
