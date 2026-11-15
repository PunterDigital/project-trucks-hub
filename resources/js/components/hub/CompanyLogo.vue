<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { CompanySummary } from '@/types/hub';

// A company's uploaded logo (#4644), or its tag in a box when it has none.
const props = defineProps<{
    company: Pick<CompanySummary, 'name' | 'tag' | 'logo'>;
    class?: string;
}>();
</script>

<template>
    <img
        v-if="company.logo"
        :src="company.logo"
        :alt="`${company.name} logo`"
        :class="cn('size-10 shrink-0 rounded-md object-cover', props.class)"
        loading="lazy"
        data-test="company-logo"
    />
    <span
        v-else
        aria-hidden="true"
        :class="
            cn(
                'flex size-10 shrink-0 items-center justify-center rounded-md bg-muted text-[0.6rem] font-semibold text-muted-foreground',
                props.class,
            )
        "
        >{{ company.tag }}</span
    >
</template>
