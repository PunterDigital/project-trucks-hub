<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { formatDate } from '@/lib/format';
import { index, show } from '@/routes/blog';
import type { PostPage, PostSummary } from '@/types/hub';

const props = defineProps<{
    post: PostPage;
    preview: boolean;
    newer: PostSummary | null;
    older: PostSummary | null;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Blog', href: index() }] },
});

const previewNote = props.preview
    ? props.post.status === 'draft'
        ? 'Draft preview: only staff can see this page. Publish the post in the admin panel to show it.'
        : props.post.is_public
          ? 'Preview of the published post.'
          : 'Scheduled: only staff can see this page until its publish time.'
    : null;
</script>

<template>
    <Head :title="post.title" />

    <article class="blog-post" data-test="blog-post">
        <p
            v-if="previewNote"
            class="mb-6 rounded-md border border-amber-500/50 bg-amber-500/10 px-4 py-2 text-sm text-amber-800 dark:text-amber-300"
            data-test="blog-preview-note"
        >
            {{ previewNote }}
        </p>

        <img
            v-if="post.cover"
            :src="post.cover"
            alt=""
            width="1200"
            height="630"
            class="mb-6 aspect-[1200/630] w-full rounded-xl object-cover"
        />

        <h1>{{ post.title }}</h1>
        <p class="text-sm text-muted-foreground">
            <time v-if="post.published_at" :datetime="post.published_at">{{
                formatDate(post.published_at)
            }}</time>
            <span v-else>Not published yet</span>
        </p>

        <!-- Rendered on the server with raw HTML stripped and unsafe links dropped (Post::bodyHtml). -->
        <div class="blog-body" data-test="blog-body" v-html="post.html" />

        <nav
            v-if="newer || older"
            class="mt-12 flex flex-col gap-3 border-t pt-6 text-sm sm:flex-row sm:justify-between"
            aria-label="More posts"
        >
            <Link
                v-if="older"
                :href="show(older.slug).url"
                class="hover:underline"
                >‹ {{ older.title }}</Link
            >
            <span v-else />
            <Link
                v-if="newer"
                :href="show(newer.slug).url"
                class="hover:underline"
                >{{ newer.title }} ›</Link
            >
        </nav>
    </article>
</template>

<style scoped>
/* Plain reading layout, like /privacy (no typography plugin in this app). */
.blog-post {
    max-width: 48rem;
    width: 100%;
    margin: 0 auto;
    padding: 2rem 1rem 4rem;
    line-height: 1.7;
}
.blog-post h1 {
    font-size: 1.875rem;
    font-weight: 600;
    line-height: 1.25;
    margin-bottom: 0.25rem;
}
.blog-body {
    margin-top: 1.5rem;
}
.blog-body :deep(h1),
.blog-body :deep(h2) {
    font-size: 1.375rem;
    font-weight: 600;
    margin-top: 2rem;
}
.blog-body :deep(h3),
.blog-body :deep(h4) {
    font-size: 1.125rem;
    font-weight: 600;
    margin-top: 1.5rem;
}
.blog-body :deep(p),
.blog-body :deep(ul),
.blog-body :deep(ol),
.blog-body :deep(blockquote),
.blog-body :deep(pre),
.blog-body :deep(table) {
    margin-top: 0.875rem;
}
.blog-body :deep(ul) {
    list-style: disc;
    padding-left: 1.5rem;
}
.blog-body :deep(ol) {
    list-style: decimal;
    padding-left: 1.5rem;
}
.blog-body :deep(li) {
    margin-top: 0.375rem;
}
.blog-body :deep(a) {
    text-decoration: underline;
    text-underline-offset: 4px;
}
.blog-body :deep(blockquote) {
    border-left: 3px solid var(--border);
    padding-left: 1rem;
    color: var(--muted-foreground);
}
.blog-body :deep(code) {
    font-size: 0.875em;
    background: var(--muted);
    border-radius: 0.25rem;
    padding: 0.1rem 0.3rem;
}
.blog-body :deep(pre) {
    background: var(--muted);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    overflow-x: auto;
}
.blog-body :deep(pre code) {
    background: none;
    padding: 0;
}
.blog-body :deep(img) {
    max-width: 100%;
    border-radius: 0.5rem;
}
.blog-body :deep(table) {
    border-collapse: collapse;
}
.blog-body :deep(th),
.blog-body :deep(td) {
    border: 1px solid var(--border);
    padding: 0.375rem 0.625rem;
}
</style>
