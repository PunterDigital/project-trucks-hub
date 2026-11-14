<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- The ProjectTrucks app icon (copied from project-trucks/assets/icon). -->
    <img src="/favicon.svg" alt="" :class="className" v-bind="$attrs" />
</template>
