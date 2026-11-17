<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { UserCheck, UserPlus } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { follow, unfollow } from '@/routes/drivers';

// Follow / Unfollow on a profile (#4647); the page hides it on your own profile and for guests.
const props = defineProps<{
    username: string;
    following: boolean;
}>();

const busy = ref(false);

function toggle() {
    if (busy.value) {
        return;
    }

    busy.value = true;
    const route = props.following
        ? unfollow(props.username)
        : follow(props.username);
    router.visit(route.url, {
        method: route.method,
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (busy.value = false),
    });
}
</script>

<template>
    <Button
        type="button"
        size="sm"
        :variant="following ? 'outline' : 'default'"
        :aria-pressed="following"
        :disabled="busy"
        data-test="follow"
        @click="toggle"
    >
        <UserCheck v-if="following" class="size-4" />
        <UserPlus v-else class="size-4" />
        {{ following ? 'Following' : 'Follow' }}
    </Button>
</template>
