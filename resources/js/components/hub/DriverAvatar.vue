<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';

const props = defineProps<{
    name: string;
    avatar?: string | null;
    class?: string;
}>();

const { getInitials } = useInitials();
</script>

<template>
    <Avatar :class="cn('size-8 overflow-hidden rounded-full', props.class)">
        <AvatarImage v-if="avatar" :src="avatar" :alt="name" />
        <AvatarFallback class="rounded-full text-xs">
            {{ getInitials(name) }}
        </AvatarFallback>
    </Avatar>
</template>
