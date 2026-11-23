<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { MonitorSmartphone, TriangleAlert } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { privacy } from '@/routes';
import { show, update } from '@/routes/link';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Link ProjectTrucks', href: show() }] },
});

defineProps<{
    code: string;
    request: {
        user_code: string;
        client_name: string;
        expires_at: string;
    } | null;
    invalid: boolean;
}>();
</script>

<template>
    <Head title="Link ProjectTrucks" />

    <div class="mx-auto flex max-w-md flex-col gap-6 p-4 pt-10">
        <div class="space-y-2 text-center">
            <MonitorSmartphone class="mx-auto size-10 text-amber-500" />
            <h1 class="text-xl font-semibold">Link ProjectTrucks</h1>
        </div>

        <template v-if="request">
            <div
                class="rounded-xl border p-5 text-center"
                data-test="link-request"
            >
                <p class="text-sm text-muted-foreground">
                    <strong class="text-foreground">{{
                        request.client_name
                    }}</strong>
                    wants to sync jobs to your account.
                </p>
                <p
                    class="mt-3 font-mono text-2xl font-semibold tracking-widest"
                >
                    {{ request.user_code }}
                </p>
                <p class="mt-3 text-xs text-muted-foreground">
                    Check that this matches the code in ProjectTrucks. Once
                    approved, new jobs upload automatically and appear on your
                    public profile; you can hide single jobs or make your
                    profile private.
                    <Link
                        :href="privacy()"
                        class="underline underline-offset-4"
                        data-test="link-privacy"
                        >What gets synced</Link
                    >.
                </p>
            </div>
            <div
                class="flex gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm"
                role="note"
                data-test="link-warning"
            >
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
                />
                <p>
                    Only approve a code that your own ProjectTrucks app is
                    showing you right now. If someone sent you this link or the
                    code, decline: approving lets their app sync jobs to your
                    account.
                </p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <Form v-bind="update.form()" v-slot="{ processing }">
                    <input
                        type="hidden"
                        name="user_code"
                        :value="request.user_code"
                    />
                    <input type="hidden" name="decision" value="deny" />
                    <Button
                        variant="outline"
                        class="w-full"
                        :disabled="processing"
                        >Decline</Button
                    >
                </Form>
                <Form v-bind="update.form()" v-slot="{ processing }">
                    <input
                        type="hidden"
                        name="user_code"
                        :value="request.user_code"
                    />
                    <input type="hidden" name="decision" value="approve" />
                    <Button
                        class="w-full"
                        :disabled="processing"
                        data-test="approve"
                        >Approve</Button
                    >
                </Form>
            </div>
        </template>

        <Form
            v-else
            v-bind="show.form()"
            class="space-y-4"
            v-slot="{ processing }"
        >
            <p class="text-center text-sm text-muted-foreground">
                In ProjectTrucks open
                <strong>Settings → ProjectTrucks Hub</strong>, click
                <strong>Link account</strong> and enter the code it shows.
            </p>
            <div class="grid gap-2">
                <Label for="code">Code</Label>
                <Input
                    id="code"
                    name="code"
                    :default-value="code"
                    placeholder="ABCD-1234"
                    autocomplete="off"
                    class="text-center font-mono text-lg tracking-widest uppercase"
                    required
                    v-focus
                />
                <InputError
                    :message="
                        invalid
                            ? 'That code is wrong or has expired. Start linking again in ProjectTrucks.'
                            : undefined
                    "
                />
            </div>
            <Button class="w-full" :disabled="processing">Continue</Button>
        </Form>
    </div>
</template>
