<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <footer class="text-center text-xs text-slate-600 py-4">
    <span>All EVE-related materials are property of CCP Games.</span>
    <span class="mx-1">|</span>
    <RouterLink to="/legal#legal-notice" class="hover:text-slate-400 transition-colors">Legal Notice</RouterLink>
    <span class="mx-1">&middot;</span>
    <RouterLink to="/legal#privacy-policy" class="hover:text-slate-400 transition-colors">Privacy Policy</RouterLink>
    <span class="mx-1">&middot;</span>
    <RouterLink to="/legal#ccp-disclaimer" class="hover:text-slate-400 transition-colors">CCP Disclaimer</RouterLink>
  </footer>
</template>
