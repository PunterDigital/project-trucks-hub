<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as companies, show as showCompany } from '@/routes/companies';
import { store } from '@/routes/companies/news';
import { update } from '@/routes/news';
import type { CompanySummary, NewsPost } from '@/types/hub';

// Write or edit a company news post (#5224).
const props = defineProps<{
    company: CompanySummary;
    post: NewsPost | null;
    // The company's Discord webhook is on (new posts only).
    discord: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Companies', href: companies() }] },
});

const action = computed(() =>
    props.post ? update.form(props.post.id) : store.form(props.company.slug),
);
const inputClass =
    'rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<template>
    <Head :title="post ? 'Edit news' : 'Post news'" />

    <div class="max-w-xl p-4">
        <h1 class="mb-1 text-xl font-semibold">
            {{ post ? 'Edit news' : 'Post news' }}
        </h1>
        <p class="mb-6 text-sm text-muted-foreground">
            For
            <a
                :href="showCompany(company.slug).url"
                class="font-medium text-foreground hover:underline"
                >[{{ company.tag }}] {{ company.name }}</a
            >.
            <template v-if="!post"
                >Members get a notification under the bell.</template
            >
        </p>
        <Form v-bind="action" class="space-y-5" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="title">Title</Label>
                <Input
                    id="title"
                    name="title"
                    required
                    maxlength="120"
                    :default-value="post?.title"
                    placeholder="New convoy route this Friday"
                />
                <InputError :message="errors.title" />
            </div>

            <div class="grid gap-2">
                <Label for="body">Message</Label>
                <textarea
                    id="body"
                    name="body"
                    rows="8"
                    required
                    maxlength="5000"
                    :class="inputClass"
                    placeholder="Plain text. Line breaks and web links are kept."
                    :value="post?.body ?? ''"
                />
                <InputError :message="errors.body" />
            </div>

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">
                    Who can read it
                </legend>
                <label class="flex items-start gap-2 text-sm">
                    <input
                        type="radio"
                        name="visibility"
                        value="members"
                        class="mt-0.5"
                        :checked="(post?.visibility ?? 'members') === 'members'"
                    />
                    <span>
                        <span class="font-medium">Members only</span>
                        <span class="block text-muted-foreground"
                            >Only drivers in the company see it.</span
                        >
                    </span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input
                        type="radio"
                        name="visibility"
                        value="public"
                        class="mt-0.5"
                        :checked="post?.visibility === 'public'"
                    />
                    <span>
                        <span class="font-medium">Public</span>
                        <span class="block text-muted-foreground"
                            >Anyone visiting the company page sees it.</span
                        >
                    </span>
                </label>
                <InputError :message="errors.visibility" />
            </fieldset>

            <label
                v-if="!post && discord"
                class="flex items-start gap-2 text-sm"
            >
                <input
                    type="checkbox"
                    name="discord"
                    value="1"
                    class="mt-0.5"
                />
                <span>
                    <span class="font-medium">Also post to Discord</span>
                    <span class="block text-muted-foreground"
                        >Sends it to the company's Discord channel through its
                        webhook.</span
                    >
                </span>
            </label>

            <Button :disabled="processing" data-test="save-news">{{
                post ? 'Save' : 'Post'
            }}</Button>
        </Form>
    </div>
</template>
