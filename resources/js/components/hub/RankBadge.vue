<script setup lang="ts">
import type { CompanyRank } from '@/types/hub';

// Company rank (#5223). The company picks any colour, so it only fills the dot: the name stays
// in the normal text colour and is readable in both themes whatever was picked.
defineProps<{ rank: Pick<CompanyRank, 'name' | 'color'> }>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border px-2 py-px text-xs font-medium normal-case"
        data-test="rank"
    >
        <span
            class="size-2 shrink-0 rounded-full"
            :style="{ backgroundColor: rank.color }"
            aria-hidden="true"
        />
        {{ rank.name }}
    </span>
</template>
