<script setup lang="ts">
import { Form, router, usePage } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, timeAgo } from '@/lib/format';
import { destroy, store } from '@/routes/companies/invites';

export type CompanyInvite = {
    id: number;
    created_at: string | null;
    expires_at: string | null;
    max_uses: number | null;
    uses: number;
    created_by: string | null;
};

const props = defineProps<{
    slug: string;
    invites: CompanyInvite[];
}>();

// The link exists in full only in the flash right after it's made (the server keeps a hash).
// Kept in a ref: the auto-refresh's reload a minute later comes without the flash.
const page = usePage();
const created = ref<{ id: number; url: string } | null>(null);
watch(
    () =>
        (page.flash as { invite?: { id: number; url: string } } | undefined)
            ?.invite,
    (invite) => {
        if (invite) {
            created.value = invite;
        }
    },
    { immediate: true },
);

const { copy, copied } = useClipboard({ legacy: true });

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function revoke(invite: CompanyInvite) {
    if (confirm('Revoke this invite link? Nobody will be able to use it.')) {
        router.delete(destroy([props.slug, invite.id]).url, {
            preserveScroll: true,
        });
    }
}

function usesLabel(invite: CompanyInvite): string {
    return invite.max_uses === null
        ? `${invite.uses} used`
        : `${invite.uses} of ${invite.max_uses} used`;
}
</script>

<template>
    <section id="invites" class="space-y-4">
        <div>
            <h2 class="font-semibold">Invite links</h2>
            <p class="text-sm text-muted-foreground">
                Anyone with a link can join straight away, even while
                recruitment is set to Applications or Closed. Share it only with
                the drivers you want.
            </p>
        </div>

        <div
            v-if="created"
            class="space-y-2 rounded-md border border-green-600/40 p-3"
            data-test="new-invite"
        >
            <p class="text-sm">
                Your new link. Copy it now: it won't be shown again.
            </p>
            <div class="flex gap-2">
                <Input
                    :model-value="created.url"
                    readonly
                    aria-label="New invite link"
                    data-test="new-invite-url"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <Button
                    type="button"
                    variant="secondary"
                    @click="copy(created.url)"
                    >{{ copied ? 'Copied' : 'Copy' }}</Button
                >
            </div>
        </div>

        <Form
            v-bind="store.form(slug)"
            :options="{ preserveScroll: true }"
            class="flex flex-wrap items-end gap-3"
            v-slot="{ processing }"
        >
            <div class="grid gap-2">
                <Label for="expires_days">Expires</Label>
                <select
                    id="expires_days"
                    name="expires_days"
                    :class="selectClass"
                >
                    <option value="1">After 1 day</option>
                    <option value="7" selected>After 7 days</option>
                    <option value="0">Never</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="max_uses">Can be used</Label>
                <select id="max_uses" name="max_uses" :class="selectClass">
                    <option value="1" selected>Once</option>
                    <option value="5">5 times</option>
                    <option value="25">25 times</option>
                    <option value="0">Any number of times</option>
                </select>
            </div>
            <Button :disabled="processing" data-test="create-invite"
                >Create invite link</Button
            >
        </Form>

        <p v-if="invites.length === 0" class="text-sm text-muted-foreground">
            No live invite links.
        </p>
        <ul v-else class="divide-y rounded-md border" data-test="invites">
            <li
                v-for="invite in invites"
                :key="invite.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 text-sm"
                data-test="invite"
                :data-invite="invite.id"
            >
                <span class="flex-1">
                    Made {{ timeAgo(invite.created_at) }}
                    <template v-if="invite.created_by"
                        >by {{ invite.created_by }}</template
                    >
                    ·
                    {{
                        invite.expires_at
                            ? `expires ${formatDateTime(invite.expires_at)}`
                            : 'never expires'
                    }}
                </span>
                <span class="text-muted-foreground" data-test="invite-uses">{{
                    usesLabel(invite)
                }}</span>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="revoke(invite)"
                    >Revoke</Button
                >
            </li>
        </ul>
    </section>
</template>
