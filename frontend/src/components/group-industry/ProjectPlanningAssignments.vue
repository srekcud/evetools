<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { PlanningCharacter } from '@/stores/group-industry/types'
import UnknownContributionValue from './UnknownContributionValue.vue'

defineProps<{
  characters: PlanningCharacter[]
}>()

const SKILL_COLUMNS = ['industry', 'advancedIndustry', 'reactions'] as const
const SLOT_COLUMNS = ['manufacturing', 'reaction'] as const

const { t } = useI18n()
const { formatDateTime } = useFormatters()

function endsAtCell(character: PlanningCharacter): 'known' | 'none' | 'unknown' {
  if (character.endsAt !== null) return 'known'
  return !character.skillsUnknown && character.jobs.length === 0 ? 'none' : 'unknown'
}
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-left text-xs uppercase tracking-wider text-slate-500 border-b border-slate-800">
          <th class="px-4 py-2 font-medium">{{ t('groupIndustry.planning.columns.character') }}</th>
          <th class="px-2 py-2 font-medium text-right">{{ t('groupIndustry.planning.columns.manufacturingSlots') }}</th>
          <th class="px-2 py-2 font-medium text-right">{{ t('groupIndustry.planning.columns.reactionSlots') }}</th>
          <th class="px-2 py-2 font-medium text-right">{{ t('groupIndustry.planning.columns.industry') }}</th>
          <th class="px-2 py-2 font-medium text-right">{{ t('groupIndustry.planning.columns.advancedIndustry') }}</th>
          <th class="px-2 py-2 font-medium text-right">{{ t('groupIndustry.planning.columns.reactions') }}</th>
          <th class="px-2 py-2 font-medium">{{ t('groupIndustry.planning.columns.jobs') }}</th>
          <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.planning.columns.endsAt') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="character in characters"
          :key="character.characterName"
          :data-testid="`planning-assignment-${character.characterName}`"
          class="border-b border-slate-800/60 align-top"
        >
          <td class="px-4 py-2 max-w-[14rem]">
            <span
              data-testid="assignment-name"
              :title="character.characterName"
              class="block truncate text-slate-200"
            >{{ character.characterName }}</span>
          </td>
          <td
            v-for="slot in SLOT_COLUMNS"
            :key="slot"
            :data-testid="`assignment-slots-${slot}`"
            class="px-2 py-2 text-right tabular-nums text-slate-300"
          >
            <span v-if="character.slots !== null">{{ character.slots[slot] }}</span>
            <UnknownContributionValue v-else data-testid="planning-unknown" />
          </td>
          <td
            v-for="skill in SKILL_COLUMNS"
            :key="skill"
            :data-testid="`assignment-skill-${skill}`"
            class="px-2 py-2 text-right tabular-nums text-slate-300"
          >
            <span v-if="character.skills !== null">{{ character.skills[skill] }}</span>
            <UnknownContributionValue v-else data-testid="planning-unknown" />
          </td>
          <td data-testid="assignment-jobs" class="px-2 py-2 text-slate-300">
            <UnknownContributionValue v-if="character.skillsUnknown" data-testid="planning-unknown" />
            <span v-else-if="character.jobs.length === 0" class="text-slate-500">{{ t('groupIndustry.planning.noJob') }}</span>
            <ul v-else class="space-y-0.5">
              <li v-for="job in character.jobs" :key="job.lineId" class="flex items-baseline gap-1.5 min-w-0">
                <span :title="job.typeName" class="truncate">{{ job.typeName }}</span>
                <span class="shrink-0 text-xs text-slate-500">{{ t('groupIndustry.planning.runs', { runs: job.runs }) }}</span>
              </li>
            </ul>
          </td>
          <td
            data-testid="assignment-ends-at"
            :data-value="character.endsAt ?? undefined"
            class="px-4 py-2 text-right tabular-nums text-slate-300 whitespace-nowrap"
          >
            <template v-if="endsAtCell(character) === 'known'">{{ formatDateTime(character.endsAt as string) }}</template>
            <span v-else-if="endsAtCell(character) === 'none'" class="text-slate-500">{{ t('groupIndustry.planning.noJob') }}</span>
            <UnknownContributionValue v-else data-testid="planning-unknown" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
