<script setup lang="ts">
import { RouterView } from 'vue-router'
import { dateFormat } from '@/composables/useFormatters'
import ToastContainer from '@/components/common/ToastContainer.vue'
import MiningCompanionHost from '@/components/mining-site/MiningCompanionHost.vue'
import GroupCompanionHost from '@/components/group-industry/GroupCompanionHost.vue'
</script>

<template>
  <RouterView :key="dateFormat" />
  <ToastContainer />
  <MiningCompanionHost />
  <GroupCompanionHost />
</template>
