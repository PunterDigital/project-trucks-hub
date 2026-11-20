<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { download, register } from '@/routes';
import { index, show } from '@/routes/guides';
import type { GuidePage, GuideSummary } from '@/types/hub';

defineProps<{
    guide: GuidePage;
    others: GuideSummary[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Guides', href: index() }] },
});

const page = usePage();
</script>

<template>
    <Head :title="guide.title" />

    <article class="guide" data-test="guide">
        <h1>{{ guide.title }}</h1>
        <p class="text-sm text-muted-foreground">
            Updated
            <time :datetime="guide.updated">{{ guide.updated_label }}</time>
        </p>

        <p class="guide-answer" data-test="guide-answer">
            {{ guide.answer }}
        </p>

        <!-- Rendered on the server from resources/guides, raw HTML stripped (Guides::page). -->
        <div class="guide-body" data-test="guide-body" v-html="guide.html" />

        <section class="mt-10" aria-labelledby="faq" data-test="guide-faq">
            <h2 id="faq" class="guide-h2">Questions</h2>
            <div v-for="item in guide.faq" :key="item.question" class="mt-5">
                <h3 class="font-semibold">{{ item.question }}</h3>
                <p class="mt-1">{{ item.answer }}</p>
            </div>
        </section>

        <aside
            class="mt-10 flex flex-col gap-3 rounded-xl border bg-muted/40 p-5 sm:flex-row sm:items-center sm:justify-between"
            data-test="guide-cta"
        >
            <p class="text-sm">
                ProjectTrucks is free. Install it, drive, and your jobs show up
                on your Hub profile.
            </p>
            <div class="flex shrink-0 gap-2">
                <Link
                    :href="download()"
                    class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:opacity-90"
                    >Get ProjectTrucks</Link
                >
                <Link
                    v-if="!page.props.auth.user"
                    :href="register()"
                    class="rounded-md border px-3 py-1.5 text-sm hover:bg-muted"
                    >Create an account</Link
                >
            </div>
        </aside>

        <nav
            v-if="others.length"
            class="mt-10 border-t pt-6"
            aria-label="More guides"
        >
            <h2 class="guide-h2">More guides</h2>
            <ul class="mt-3 flex flex-col gap-2">
                <li v-for="other in others" :key="other.slug">
                    <Link :href="show(other.slug).url" class="hover:underline">
                        {{ other.title }}
                    </Link>
                </li>
            </ul>
        </nav>
    </article>
</template>

<style scoped>
/* Same reading layout as a blog post (blog/Show.vue). */
.guide {
    max-width: 48rem;
    width: 100%;
    margin: 0 auto;
    padding: 2rem 1rem 4rem;
    line-height: 1.7;
}
.guide h1 {
    font-size: 1.875rem;
    font-weight: 600;
    line-height: 1.25;
    margin-bottom: 0.25rem;
}
.guide-answer {
    margin-top: 1.25rem;
    border-left: 3px solid var(--primary);
    padding-left: 1rem;
    font-size: 1.0625rem;
}
.guide-h2,
.guide-body :deep(h2) {
    font-size: 1.375rem;
    font-weight: 600;
    margin-top: 2rem;
}
.guide-body {
    margin-top: 1.5rem;
}
.guide-body :deep(h3) {
    font-size: 1.125rem;
    font-weight: 600;
    margin-top: 1.5rem;
}
.guide-body :deep(p),
.guide-body :deep(ul),
.guide-body :deep(ol),
.guide-body :deep(blockquote),
.guide-body :deep(table) {
    margin-top: 0.875rem;
}
.guide-body :deep(ul) {
    list-style: disc;
    padding-left: 1.5rem;
}
.guide-body :deep(ol) {
    list-style: decimal;
    padding-left: 1.5rem;
}
.guide-body :deep(li) {
    margin-top: 0.375rem;
}
.guide-body :deep(a) {
    text-decoration: underline;
    text-underline-offset: 4px;
}
.guide-body :deep(blockquote) {
    border-left: 3px solid var(--border);
    padding-left: 1rem;
    color: var(--muted-foreground);
}
.guide-body :deep(code) {
    font-size: 0.875em;
    background: var(--muted);
    border-radius: 0.25rem;
    padding: 0.1rem 0.3rem;
}
.guide-body :deep(th),
.guide-body :deep(td) {
    border: 1px solid var(--border);
    padding: 0.375rem 0.625rem;
}
</style>
