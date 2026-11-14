<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <section class="rounded-xl border p-4 sm:p-5">
        <header class="mb-3 flex items-start justify-between gap-2">
            <div>
                <h2 class="font-semibold">{{ title }}</h2>
                <p v-if="description" class="text-sm text-muted-foreground">
                    {{ description }}
                </p>
            </div>
            <slot name="actions" />
        </header>
        <slot />
    </section>
</template>
