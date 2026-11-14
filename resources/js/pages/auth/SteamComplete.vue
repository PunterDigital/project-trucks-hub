<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import DriverAvatar from '@/components/hub/DriverAvatar.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/steam';

defineOptions({
    layout: {
        title: 'Finish creating your account',
        description:
            'Steam doesn’t share your email, so we need it for sign-in links and password resets.',
    },
});

defineProps<{
    nickname: string;
    avatar: string | null;
    suggestedUsername: string;
}>();
</script>

<template>
    <Head title="Finish sign-up" />

    <div class="mb-6 flex items-center justify-center gap-3">
        <!-- Without a Steam Web API key there is no Steam name or avatar, only the SteamID. -->
        <DriverAvatar
            :name="nickname || 'Steam'"
            :avatar="avatar"
            class="size-10"
        />
        <span class="font-medium">{{ nickname || 'Your Steam account' }}</span>
    </div>

    <Form
        v-bind="store.form()"
        class="flex flex-col gap-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-2">
            <Label for="name">Display name</Label>
            <Input
                id="name"
                name="name"
                required
                :default-value="nickname"
                autocomplete="nickname"
            />
            <InputError :message="errors.name" />
        </div>
        <div class="grid gap-2">
            <Label for="username">Username</Label>
            <Input
                id="username"
                name="username"
                required
                :default-value="suggestedUsername"
            />
            <p class="text-xs text-muted-foreground">
                Your profile address: /drivers/username
            </p>
            <InputError :message="errors.username" />
        </div>
        <div class="grid gap-2">
            <Label for="email">Email address</Label>
            <Input
                id="email"
                type="email"
                name="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
                v-focus
            />
            <InputError :message="errors.email" />
        </div>
        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Create account
        </Button>
    </Form>
</template>
