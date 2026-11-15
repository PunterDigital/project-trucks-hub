<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

// One picture with "Upload" and "Remove" (#4644): avatars, company logos and banners.
const props = defineProps<{
    id: string;
    label: string;
    hint: string;
    field: string;
    current: string | null;
    shape: 'round' | 'square' | 'banner';
    store: { action: string; method: 'post' };
    remove: string;
    removeLabel?: string;
}>();

function removeImage() {
    if (confirm(`${props.removeLabel ?? 'Remove'}?`)) {
        router.delete(props.remove, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="grid gap-3" :data-test="`${id}-upload`">
        <Label :for="id">{{ label }}</Label>
        <div
            :class="
                shape === 'banner'
                    ? 'flex flex-col gap-3'
                    : 'flex items-center gap-4'
            "
        >
            <img
                v-if="current"
                :src="current"
                :alt="label"
                :class="{
                    'size-20 rounded-full object-cover': shape === 'round',
                    'size-20 rounded-md object-cover': shape === 'square',
                    'aspect-[15/4] w-full rounded-lg object-cover':
                        shape === 'banner',
                }"
                :data-test="`${id}-current`"
            />
            <div
                v-else
                :class="{
                    'size-20 rounded-full': shape === 'round',
                    'size-20 rounded-md': shape === 'square',
                    'aspect-[15/4] w-full rounded-lg': shape === 'banner',
                }"
                class="flex items-center justify-center bg-muted text-xs text-muted-foreground"
            >
                None
            </div>
            <Form
                v-bind="store"
                class="flex min-w-0 flex-1 flex-col gap-2"
                :options="{ preserveScroll: true }"
                reset-on-success
                v-slot="{ errors, processing }"
            >
                <input
                    :id="id"
                    type="file"
                    :name="field"
                    accept="image/png,image/jpeg,image/webp"
                    required
                    class="block w-full text-sm file:mr-3 file:rounded-md file:border file:bg-background file:px-3 file:py-1.5 file:text-sm"
                />
                <p class="text-xs text-muted-foreground">{{ hint }}</p>
                <InputError :message="errors[field]" />
                <div class="flex gap-2">
                    <Button
                        size="sm"
                        :disabled="processing"
                        :data-test="`${id}-submit`"
                        >Upload</Button
                    >
                    <Button
                        v-if="current"
                        type="button"
                        size="sm"
                        variant="outline"
                        :data-test="`${id}-remove`"
                        @click="removeImage"
                        >{{ removeLabel ?? 'Remove' }}</Button
                    >
                </div>
            </Form>
        </div>
    </div>
</template>
