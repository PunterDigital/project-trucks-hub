<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Rss } from '@lucide/vue';
import Pagination from '@/components/hub/Pagination.vue';
import { formatDate } from '@/lib/format';
import { feed, index, show } from '@/routes/blog';
import type { Paginated, PostSummary } from '@/types/hub';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Blog', href: index() }] },
});

defineProps<{
    posts: Paginated<PostSummary>;
}>();
</script>

<template>
    <Head title="Blog" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Blog</h1>
                <p class="text-sm text-muted-foreground">
                    News, guides and release notes from ProjectTrucks and the
                    Hub.
                </p>
            </div>
            <a
                :href="feed().url"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm hover:bg-muted"
                data-test="blog-feed-link"
            >
                <Rss class="size-4" />
                RSS
            </a>
        </div>

        <p
            v-if="posts.data.length === 0"
            class="rounded-xl border p-6 text-sm text-muted-foreground"
        >
            No posts yet.
        </p>

        <ul v-else class="flex flex-col gap-4" data-test="blog-posts">
            <li v-for="post in posts.data" :key="post.id">
                <Link
                    :href="show(post.slug).url"
                    class="group flex flex-col overflow-hidden rounded-xl border transition-colors hover:bg-muted/50 sm:flex-row"
                >
                    <img
                        v-if="post.cover"
                        :src="post.cover"
                        alt=""
                        width="1200"
                        height="630"
                        loading="lazy"
                        class="aspect-[1200/630] w-full object-cover sm:w-56"
                    />
                    <div class="flex flex-col gap-1 p-4">
                        <h2 class="text-lg font-semibold group-hover:underline">
                            {{ post.title }}
                        </h2>
                        <time
                            v-if="post.published_at"
                            :datetime="post.published_at"
                            class="text-xs text-muted-foreground"
                        >
                            {{ formatDate(post.published_at) }}
                        </time>
                        <p class="text-sm text-muted-foreground">
                            {{ post.excerpt }}
                        </p>
                    </div>
                </Link>
            </li>
        </ul>

        <Pagination :links="posts.links" :last-page="posts.last_page" />
    </div>
</template>
