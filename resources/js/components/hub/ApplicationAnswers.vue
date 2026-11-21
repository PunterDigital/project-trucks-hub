<script setup lang="ts">
// Answers to a company's application questions (#5222), as asked when the driver applied.
export type ApplicationAnswer = {
    question: string;
    type: string;
    answer: string | null;
};

defineProps<{ answers: ApplicationAnswer[] }>();

function shown(a: ApplicationAnswer): string {
    if (a.answer === null) {
        return 'No answer';
    }
    if (a.type === 'yes_no') {
        return a.answer === 'yes' ? 'Yes' : 'No';
    }

    return a.answer;
}
</script>

<template>
    <dl class="space-y-2 text-sm" data-test="answers">
        <div v-for="(a, i) in answers" :key="i">
            <dt class="font-medium">{{ a.question }}</dt>
            <dd
                class="whitespace-pre-line"
                :class="a.answer === null ? 'text-muted-foreground italic' : ''"
            >
                {{ shown(a) }}
            </dd>
        </div>
    </dl>
</template>
