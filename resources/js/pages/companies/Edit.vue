<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import CompanyForm from '@/components/hub/CompanyForm.vue';
import ImageUpload from '@/components/hub/ImageUpload.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, update } from '@/routes/companies';
import {
    destroy as destroyImage,
    store as storeImage,
} from '@/routes/companies/images';
import type { CompanySummary, Recruitment } from '@/types/hub';

defineProps<{
    company: CompanySummary & {
        description: string | null;
        recruitment: Recruitment;
        banner: string | null;
    };
    isOwner: boolean;
}>();
</script>

<template>
    <Head :title="`Edit ${company.name}`" />

    <div class="max-w-xl space-y-10 p-4">
        <div>
            <h1 class="mb-6 text-xl font-semibold">Edit {{ company.name }}</h1>
            <Form
                v-bind="update.form(company.slug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <CompanyForm :errors="errors" :company="company" />
                <Button :disabled="processing">Save</Button>
            </Form>
        </div>

        <section class="space-y-6">
            <h2 class="font-semibold">Logo and banner</h2>
            <ImageUpload
                id="logo"
                label="Logo"
                hint="PNG, JPEG or WebP, up to 2 MB. Cropped to a square (256 × 256)."
                field="image"
                shape="square"
                :current="company.logo ?? null"
                :store="storeImage.form([company.slug, 'logo'])"
                :remove="destroyImage.url([company.slug, 'logo'])"
                remove-label="Remove logo"
            />
            <ImageUpload
                id="banner"
                label="Banner"
                hint="PNG, JPEG or WebP, up to 2 MB. Cropped to 1500 × 400."
                field="image"
                shape="banner"
                :current="company.banner"
                :store="storeImage.form([company.slug, 'banner'])"
                :remove="destroyImage.url([company.slug, 'banner'])"
                remove-label="Remove banner"
            />
        </section>

        <section
            v-if="isOwner"
            class="space-y-4 rounded-xl border border-red-500/40 p-4"
        >
            <div>
                <h2 class="font-semibold">Delete company</h2>
                <p class="text-sm text-muted-foreground">
                    Removes the company and its roster. Members keep their jobs
                    on their own profiles. This can't be undone.
                </p>
            </div>
            <Form
                v-bind="destroy.form(company.slug)"
                class="space-y-3"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="confirm_name"
                        >Type <strong>{{ company.name }}</strong> to
                        confirm</Label
                    >
                    <Input
                        id="confirm_name"
                        name="confirm_name"
                        autocomplete="off"
                    />
                    <InputError :message="errors.confirm_name" />
                </div>
                <Button variant="destructive" :disabled="processing"
                    >Delete company</Button
                >
            </Form>
        </section>
    </div>
</template>
