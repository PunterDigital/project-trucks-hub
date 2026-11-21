<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import ApplicationQuestionsEditor from '@/components/hub/ApplicationQuestionsEditor.vue';
import type { ApplicationQuestion } from '@/components/hub/ApplicationQuestionsEditor.vue';
import CompanyForm from '@/components/hub/CompanyForm.vue';
import CompanyInvites from '@/components/hub/CompanyInvites.vue';
import CompanyRanksEditor from '@/components/hub/CompanyRanksEditor.vue';
import type { CompanyInvite } from '@/components/hub/CompanyInvites.vue';
import ImageUpload from '@/components/hub/ImageUpload.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import { destroy, update } from '@/routes/companies';
import {
    destroy as destroyDiscord,
    test as testDiscord,
    update as updateDiscord,
} from '@/routes/companies/discord';
import {
    destroy as destroyImage,
    store as storeImage,
} from '@/routes/companies/images';
import type { CompanyRank, CompanySummary, Recruitment } from '@/types/hub';

defineProps<{
    company: CompanySummary & {
        description: string | null;
        recruitment: Recruitment;
        banner: string | null;
    };
    discord: {
        set: boolean;
        hint: string | null;
        disabled_at: string | null;
        error: string | null;
    };
    invites: CompanyInvite[];
    questions: ApplicationQuestion[];
    ranks: CompanyRank[];
    isOwner: boolean;
}>();

// In the script, not the template: vue-tsc 3.3.12+ breaks on `https://` in a template
// attribute (vuejs/language-tools#6240).
const webhookPlaceholder = 'https://discord.com/api/webhooks/…';
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

        <CompanyInvites :slug="company.slug" :invites="invites" />

        <ApplicationQuestionsEditor
            :slug="company.slug"
            :questions="questions"
        />

        <CompanyRanksEditor :slug="company.slug" :ranks="ranks" />

        <section id="discord" class="space-y-4">
            <div>
                <h2 class="font-semibold">Discord</h2>
                <p class="text-sm text-muted-foreground">
                    Post counted jobs, new members and new events to a channel
                    on your Discord server. In Discord: Server Settings →
                    Integrations → Webhooks → New Webhook, then Copy Webhook URL
                    and paste it here. Everyone in that channel sees the posts,
                    including jobs by members with a private profile.
                </p>
            </div>
            <p
                v-if="discord.set && !discord.disabled_at"
                class="text-sm"
                data-test="discord-status"
            >
                Connected: the webhook ending in
                <code>{{ discord.hint }}</code
                >.
            </p>
            <p
                v-if="discord.disabled_at"
                class="rounded-md border border-red-500/40 p-3 text-sm"
                data-test="discord-status"
            >
                Switched off {{ formatDateTime(discord.disabled_at) }}:
                {{ discord.error }} Send a test message or save the URL again to
                turn it back on.
            </p>
            <Form
                v-bind="updateDiscord.form(company.slug)"
                class="space-y-3"
                reset-on-success
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="webhook">{{
                        discord.set ? 'Replace the webhook URL' : 'Webhook URL'
                    }}</Label>
                    <Input
                        id="webhook"
                        name="webhook"
                        type="password"
                        autocomplete="off"
                        :placeholder="webhookPlaceholder"
                    />
                    <InputError :message="errors.webhook" />
                </div>
                <Button :disabled="processing">Save webhook</Button>
            </Form>
            <div v-if="discord.set" class="flex flex-wrap gap-2">
                <Form
                    v-bind="testDiscord.form(company.slug)"
                    v-slot="{ processing }"
                >
                    <Button variant="secondary" :disabled="processing"
                        >Send test message</Button
                    >
                </Form>
                <Form
                    v-bind="destroyDiscord.form(company.slug)"
                    v-slot="{ processing }"
                >
                    <Button variant="outline" :disabled="processing"
                        >Remove webhook</Button
                    >
                </Form>
            </div>
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
