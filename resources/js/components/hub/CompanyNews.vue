<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Lock, Pencil, Pin, PinOff, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { timeAgo } from '@/lib/format';
import { show as companyPage } from '@/routes/companies';
import { show as showDriver } from '@/routes/drivers';
import { destroy, edit, pin } from '@/routes/news';
import type { NewsPost } from '@/types/hub';

// Company news (#5224): the company page's panel and the home page's My company tab.
withDefaults(
    defineProps<{
        posts: NewsPost[];
        canManage?: boolean;
        showCompany?: boolean;
        empty?: string;
    }>(),
    { canManage: false, showCompany: false, empty: 'No news yet.' },
);

// Bodies are plain text: line breaks kept by CSS, web links turned into <a> here, never v-html.
const URL_RE = /(https?:\/\/[^\s<>"]+[^\s<>".,:;!?'")\]])/g;

type Segment = { text: string; href: string | null };

function segments(body: string): Segment[] {
    return body
        .split(URL_RE)
        .filter((part) => part !== '')
        .map((part) => ({
            text: part,
            href: /^https?:\/\//.test(part) ? part : null,
        }));
}

function togglePin(post: NewsPost) {
    router.put(
        pin(post.id).url,
        { pinned: !post.pinned },
        { preserveScroll: true },
    );
}

function remove(post: NewsPost) {
    if (!confirm(`Delete “${post.title}”? This can't be undone.`)) {
        return;
    }

    router.delete(destroy(post.id).url, { preserveScroll: true });
}
</script>

<template>
    <p
        v-if="posts.length === 0"
        class="py-6 text-center text-sm text-muted-foreground"
    >
        {{ empty }}
    </p>
    <ul v-else class="divide-y" data-test="news-list">
        <li
            v-for="post in posts"
            :key="post.id"
            class="space-y-1.5 py-3 first:pt-0 last:pb-0"
            data-test="news-post"
        >
            <div class="flex items-start justify-between gap-2">
                <h3 class="flex min-w-0 items-center gap-1.5 font-medium">
                    <Pin
                        v-if="post.pinned"
                        class="size-4 shrink-0 text-amber-500"
                        aria-label="Pinned"
                    />
                    <span class="break-words">{{ post.title }}</span>
                </h3>
                <div v-if="canManage" class="flex shrink-0 gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        :aria-label="post.pinned ? 'Unpin' : 'Pin to the top'"
                        :title="post.pinned ? 'Unpin' : 'Pin to the top'"
                        @click="togglePin(post)"
                    >
                        <PinOff v-if="post.pinned" class="size-4" />
                        <Pin v-else class="size-4" />
                    </Button>
                    <Button as-child variant="ghost" size="icon" class="size-8">
                        <Link
                            :href="edit(post.id)"
                            aria-label="Edit"
                            title="Edit"
                            ><Pencil class="size-4"
                        /></Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        aria-label="Delete"
                        title="Delete"
                        @click="remove(post)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </div>
            <p
                class="text-sm break-words whitespace-pre-line"
                data-test="news-body"
            >
                <template v-for="(part, i) in segments(post.body)" :key="i">
                    <a
                        v-if="part.href"
                        :href="part.href"
                        target="_blank"
                        rel="nofollow ugc noopener noreferrer"
                        class="text-primary underline underline-offset-2"
                        >{{ part.text }}</a
                    >
                    <template v-else>{{ part.text }}</template>
                </template>
            </p>
            <p
                class="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground"
            >
                <template v-if="showCompany && post.company">
                    <Link
                        :href="companyPage(post.company.slug)"
                        class="hover:underline"
                        >[{{ post.company.tag }}] {{ post.company.name }}</Link
                    >
                    ·
                </template>
                <template v-if="post.author">
                    <Link
                        v-if="post.author.username"
                        :href="showDriver(post.author.username)"
                        class="hover:underline"
                        >{{ post.author.name }}</Link
                    >
                    <span v-else>{{ post.author.name }}</span>
                    ·
                </template>
                <time v-if="post.created_at" :datetime="post.created_at">{{
                    timeAgo(post.created_at)
                }}</time>
                <span v-if="post.edited">· edited</span>
                <span
                    v-if="post.visibility === 'members'"
                    class="inline-flex items-center gap-0.5"
                    data-test="news-members-only"
                    >· <Lock class="size-3" /> Members only</span
                >
            </p>
        </li>
    </ul>
</template>
