<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { Character } from '@/types'
import type {
  ReprocessingLocation,
  ReprocessingProfile,
  ReprocessingProfileInput,
  ReprocessingRigTier,
  ReprocessingSecurity,
} from '@/composables/useReprocessingProfile'
import UnknownValue from '@/components/common/UnknownValue.vue'

const PERCENT = 100
const DEFAULT_LOCATION: ReprocessingLocation = 'npc_station'
const DEFAULT_RIG_TIER: ReprocessingRigTier = 'none'
const DEFAULT_SECURITY: ReprocessingSecurity = 'highsec'
const DEFAULT_IMPLANT_PERCENT = 0

const props = defineProps<{
  profile: ReprocessingProfile | null
  characters: Character[]
}>()

const emit = defineEmits<{
  save: [input: ReprocessingProfileInput]
  refresh: []
}>()

const { t } = useI18n()
const { formatNumber, formatDateTime } = useFormatters()

const locations: ReprocessingLocation[] = ['npc_station', 'athanor', 'tatara']
const rigTiers: ReprocessingRigTier[] = ['none', 't1', 't2']
const securities: ReprocessingSecurity[] = ['highsec', 'lowsec', 'nullsec']
const implantPercents = [0, 1, 2, 4]

const characterId = ref<number | null>(null)
const oreProcessingSkillTypeId = ref<number | null>(null)
const location = ref<ReprocessingLocation>(DEFAULT_LOCATION)
const rigTier = ref<ReprocessingRigTier>(DEFAULT_RIG_TIER)
const security = ref<ReprocessingSecurity>(DEFAULT_SECURITY)
const implantPercent = ref(DEFAULT_IMPLANT_PERCENT)

const isStructure = computed(() => location.value !== 'npc_station')
const canSave = computed(() => characterId.value !== null && oreProcessingSkillTypeId.value !== null)
const canRefresh = computed(() => props.profile !== null && props.profile.configured)
const oreProcessingSkills = computed(() => props.profile?.oreProcessingSkills ?? [])
const skillLevels = computed(() => [
  { key: 'reprocessing', level: props.profile?.reprocessingLevel ?? null },
  { key: 'reprocessingEfficiency', level: props.profile?.reprocessingEfficiencyLevel ?? null },
  { key: 'oreProcessing', level: props.profile?.oreProcessingLevel ?? null },
])

function applyProfile(profile: ReprocessingProfile | null) {
  characterId.value = profile?.characterId ?? props.characters[0]?.eveCharacterId ?? null
  oreProcessingSkillTypeId.value = profile?.oreProcessingSkillTypeId ?? null
  location.value = profile?.location ?? DEFAULT_LOCATION
  rigTier.value = profile?.rigTier ?? DEFAULT_RIG_TIER
  security.value = profile?.security ?? DEFAULT_SECURITY
  implantPercent.value = profile?.implantPercent ?? DEFAULT_IMPLANT_PERCENT
}

function onSave() {
  if (characterId.value === null || oreProcessingSkillTypeId.value === null) return
  emit('save', {
    characterId: characterId.value,
    oreProcessingSkillTypeId: oreProcessingSkillTypeId.value,
    location: location.value,
    rigTier: isStructure.value ? rigTier.value : null,
    security: isStructure.value ? security.value : null,
    implantPercent: implantPercent.value,
  })
}

watch(() => props.profile, applyProfile, { immediate: true })

const fieldClass = 'w-full min-w-0 rounded-sm border border-slate-700 bg-slate-900 px-2 py-1 text-sm text-slate-200 focus:border-cyan-500/60 focus:outline-hidden disabled:opacity-40 disabled:cursor-not-allowed'
const labelClass = 'block space-y-1'
const labelTextClass = 'text-xs uppercase tracking-wider text-slate-500'
</script>

<template>
  <section data-reprocessing-profile-panel class="space-y-4 rounded-sm border border-slate-700 bg-slate-900/60 p-4">
    <div>
      <h4 class="text-sm font-semibold uppercase tracking-wider text-cyan-300">{{ t('ledger.mining.reprocessingProfile.title') }}</h4>
      <p class="text-xs text-slate-400">{{ t('ledger.mining.reprocessingProfile.subtitle') }}</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <label :class="labelClass">
        <span :class="labelTextClass">{{ t('ledger.mining.reprocessingProfile.character') }}</span>
        <select v-model.number="characterId" data-field="character" :class="fieldClass">
          <option v-for="character in characters" :key="character.eveCharacterId" :value="character.eveCharacterId">{{ character.name }}</option>
        </select>
      </label>
      <label :class="labelClass">
        <span :class="labelTextClass">{{ t('ledger.mining.reprocessingProfile.oreProcessingSkill') }}</span>
        <select v-model.number="oreProcessingSkillTypeId" data-field="oreProcessingSkill" :class="fieldClass">
          <option :value="null" disabled>{{ t('ledger.mining.reprocessingProfile.chooseSkill') }}</option>
          <option v-for="skill in oreProcessingSkills" :key="skill.typeId" :value="skill.typeId">{{ skill.typeName }}</option>
        </select>
      </label>
      <label :class="labelClass">
        <span :class="labelTextClass">{{ t('ledger.mining.reprocessingProfile.location') }}</span>
        <select v-model="location" data-field="location" :class="fieldClass">
          <option v-for="choice in locations" :key="choice" :value="choice">{{ t(`ledger.mining.reprocessingProfile.locations.${choice}`) }}</option>
        </select>
      </label>
      <label :class="labelClass">
        <span :class="labelTextClass">{{ t('ledger.mining.reprocessingProfile.rigTier') }}</span>
        <select v-model="rigTier" data-field="rigTier" :disabled="!isStructure" :class="fieldClass">
          <option v-for="choice in rigTiers" :key="choice" :value="choice">{{ t(`ledger.mining.reprocessingProfile.rigTiers.${choice}`) }}</option>
        </select>
      </label>
      <label :class="labelClass">
        <span :class="labelTextClass">{{ t('ledger.mining.reprocessingProfile.security') }}</span>
        <select v-model="security" data-field="security" :disabled="!isStructure" :class="fieldClass">
          <option v-for="choice in securities" :key="choice" :value="choice">{{ t(`ledger.mining.reprocessingProfile.securities.${choice}`) }}</option>
        </select>
      </label>
      <label :class="labelClass">
        <span :class="labelTextClass">{{ t('ledger.mining.reprocessingProfile.implant') }}</span>
        <select v-model.number="implantPercent" data-field="implant" :class="fieldClass">
          <option v-for="choice in implantPercents" :key="choice" :value="choice">{{ choice }} %</option>
        </select>
      </label>
    </div>

    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
      <p class="text-sm text-slate-300">
        <span :class="labelTextClass">{{ t('ledger.mining.reprocessingProfile.yield') }}</span>
        <span data-reprocess-yield class="ml-2 font-mono text-lg text-cyan-300">
          <UnknownValue v-if="profile === null || profile.reprocessYield === null" />
          <template v-else>{{ formatNumber(profile.reprocessYield * PERCENT) }} %</template>
        </span>
      </p>
      <ul class="flex flex-wrap items-center gap-x-4 text-xs text-slate-400">
        <li v-for="skill in skillLevels" :key="skill.key">
          {{ t(`ledger.mining.reprocessingProfile.levels.${skill.key}`) }}
          <span :data-skill-level="skill.key" class="font-mono text-slate-200">
            <UnknownValue v-if="skill.level === null" />
            <template v-else>{{ skill.level }}</template>
          </span>
        </li>
      </ul>
    </div>

    <p
      v-if="profile !== null && profile.failure !== null"
      :data-failure="profile.failure"
      class="text-xs text-amber-500"
    >
      <span aria-hidden="true">⚠ </span>{{ t(`ledger.mining.reprocessingProfile.failures.${profile.failure}`) }}
    </p>
    <p v-if="profile !== null && profile.computedAt !== null" class="text-xs text-slate-500">
      {{ t('ledger.mining.reprocessingProfile.computedAt', { date: formatDateTime(profile.computedAt) }) }}
    </p>

    <div class="flex flex-wrap items-center gap-2">
      <button
        type="button"
        data-action="save"
        :disabled="!canSave"
        class="px-3 py-1 rounded-sm border border-cyan-500/60 bg-cyan-500/10 text-xs font-medium uppercase tracking-wider text-cyan-300 transition-colors hover:bg-cyan-500/20 disabled:opacity-40 disabled:cursor-not-allowed"
        @click="onSave"
      >
        {{ t('ledger.mining.reprocessingProfile.save') }}
      </button>
      <button
        type="button"
        data-action="refresh"
        :disabled="!canRefresh"
        class="px-3 py-1 rounded-sm border border-slate-700 text-xs font-medium uppercase tracking-wider text-slate-300 transition-colors hover:text-slate-100 disabled:opacity-40 disabled:cursor-not-allowed"
        @click="emit('refresh')"
      >
        {{ t('ledger.mining.reprocessingProfile.refresh') }}
      </button>
    </div>
  </section>
</template>
