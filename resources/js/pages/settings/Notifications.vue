<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { edit, update } from '@/routes/notification-settings';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Notifications', href: edit() }] },
});

const props = defineProps<{
    emails: {
        type: string;
        label: string;
        description: string;
        enabled: boolean;
    }[];
}>();

const form = useForm({
    enabled: props.emails.filter((e) => e.enabled).map((e) => e.type),
});

function toggle(type: string, on: boolean) {
    form.enabled = on
        ? [...new Set([...form.enabled, type])]
        : form.enabled.filter((t) => t !== type);
}

function save() {
    form.put(update().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Email notifications"
            description="Everything also shows under the bell at the top of the page. Untick what you don't want by email."
        />

        <form class="space-y-4" @submit.prevent="save">
            <label
                v-for="email in emails"
                :key="email.type"
                :for="`email-${email.type}`"
                class="flex items-start gap-3 rounded-xl border p-4"
            >
                <Checkbox
                    :id="`email-${email.type}`"
                    :aria-label="email.label"
                    :model-value="form.enabled.includes(email.type)"
                    class="mt-0.5"
                    @update:model-value="(v) => toggle(email.type, v === true)"
                />
                <span class="text-sm">
                    <span class="block font-medium">{{ email.label }}</span>
                    <span
                        v-if="email.description"
                        class="block text-muted-foreground"
                        >{{ email.description }}</span
                    >
                </span>
            </label>

            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>
    </div>
</template>
