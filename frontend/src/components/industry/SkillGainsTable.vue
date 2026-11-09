<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import IskAmount from '@/components/common/IskAmount.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'
import SellMarketUnknownValue from '@/components/industry/SellMarketUnknownValue.vue'
import type { SkillGainLine } from '@/stores/industry/skillGains'

defineProps<{
  lines: SkillGainLine[]
}>()

const { t } = useI18n()
const { formatDuration } = useFormatters()

function skillLabelOf(line: SkillGainLine): string {
  return line.skillName ?? t('industry.skillGains.unnamed', { id: line.skillId })
}

function gainCauseOf(line: SkillGainLine): string | null {
  return line.gainUnknownReason === null ? null : t(`industry.skillGains.causes.${line.gainUnknownReason}`)
}

function trainingCauseOf(line: SkillGainLine): string | null {
  return line.trainingUnknownReason === null ? null : t(`industry.skillGains.causes.${line.trainingUnknownReason}`)
}

function gainPerDayCauseOf(line: SkillGainLine): string | null {
  return gainCauseOf(line) ?? trainingCauseOf(line)
}
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-left text-xs uppercase tracking-wide text-slate-500 border-t border-slate-800">
          <th class="px-4 py-2">{{ t('industry.skillGains.columns.skill') }}</th>
          <th class="px-4 py-2">{{ t('industry.skillGains.columns.effect') }}</th>
          <th class="px-4 py-2 text-right">{{ t('industry.skillGains.columns.training') }}</th>
          <th class="px-4 py-2 text-right">{{ t('industry.skillGains.columns.monthlyGain') }}</th>
          <th class="px-4 py-2 text-right">{{ t('industry.skillGains.columns.gainPerDay') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="line in lines"
          :key="`${line.skillId}-${line.effect}`"
          data-testid="skill-gain-row"
          class="border-t border-slate-800"
        >
          <td data-testid="skill-gain-name" class="px-4 py-2 text-white max-w-[16rem]">
            <span class="block truncate" :title="skillLabelOf(line)">{{ skillLabelOf(line) }}</span>
            <span class="text-xs text-slate-500">{{ line.currentLevel }} → {{ line.targetLevel }}</span>
          </td>
          <td class="px-4 py-2 text-slate-400">{{ t(`industry.skillGains.effects.${line.effect}`) }}</td>
          <td data-testid="skill-gain-training" class="px-4 py-2 text-right font-mono text-slate-300">
            <SellMarketUnknownValue v-if="trainingCauseOf(line) !== null" :cause="trainingCauseOf(line) ?? ''" />
            <template v-else-if="line.trainingSeconds !== null">{{ formatDuration(line.trainingSeconds) }}</template>
            <UnknownValue v-else />
          </td>
          <td data-testid="skill-gain-monthly" class="px-4 py-2 text-right font-mono text-slate-300">
            <SellMarketUnknownValue v-if="gainCauseOf(line) !== null" :cause="gainCauseOf(line) ?? ''" />
            <IskAmount v-else :value="line.monthlyGain" short />
          </td>
          <td data-testid="skill-gain-per-day" class="px-4 py-2 text-right font-mono font-bold text-cyan-400">
            <SellMarketUnknownValue v-if="gainPerDayCauseOf(line) !== null" :cause="gainPerDayCauseOf(line) ?? ''" />
            <IskAmount v-else :value="line.gainPerTrainingDay" short />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
