<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update } from '@/routes/companies/questions';

export type QuestionType = 'short_text' | 'long_text' | 'yes_no' | 'choice';

export type ApplicationQuestion = {
    id: string;
    label: string;
    type: QuestionType;
    required: boolean;
    options: string[];
};

// Same limits as App\Support\ApplicationQuestions.
const MAX = 10;
const MAX_OPTIONS = 8;

const props = defineProps<{
    slug: string;
    questions: ApplicationQuestion[];
}>();

const types: { value: QuestionType; label: string }[] = [
    { value: 'short_text', label: 'Short answer' },
    { value: 'long_text', label: 'Long answer' },
    { value: 'yes_no', label: 'Yes or no' },
    { value: 'choice', label: 'Pick one option' },
];

// Options are edited as one per line; the server trims them and drops blanks.
type Row = {
    id: string | null;
    label: string;
    type: QuestionType;
    required: boolean;
    optionsText: string;
};

const form = useForm<{ rows: Row[] }>({
    rows: props.questions.map((q) => ({
        id: q.id,
        label: q.label,
        type: q.type,
        required: q.required,
        optionsText: q.options.join('\n'),
    })),
});

function add() {
    form.rows.push({
        id: null,
        label: '',
        type: 'short_text',
        required: false,
        optionsText: '',
    });
}

function move(index: number, by: number) {
    const [row] = form.rows.splice(index, 1);
    form.rows.splice(index + by, 0, row);
}

function save() {
    form.transform((data) => ({
        questions: data.rows.map((r) => ({
            id: r.id,
            label: r.label,
            type: r.type,
            required: r.required,
            options: r.type === 'choice' ? r.optionsText.split('\n') : [],
        })),
    })).put(update(props.slug).url, { preserveScroll: true });
}

function error(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `questions.${index}.${field}`
    ];
}

function listError(): string | undefined {
    return (form.errors as Record<string, string | undefined>).questions;
}

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<template>
    <section id="questions" class="space-y-4">
        <div>
            <h2 class="font-semibold">Application questions</h2>
            <p class="text-sm text-muted-foreground">
                Ask drivers up to {{ MAX }} questions when they apply. Owners
                and managers see the answers next to each application, and the
                driver sees their own. Changing a question later doesn't change
                answers already sent.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="save">
            <p
                v-if="form.rows.length === 0"
                class="text-sm text-muted-foreground"
            >
                No questions: the Apply form only has a message box.
            </p>

            <fieldset
                v-for="(row, index) in form.rows"
                :key="index"
                class="space-y-3 rounded-xl border p-4"
                data-test="question"
            >
                <legend class="sr-only">Question {{ index + 1 }}</legend>
                <div class="grid gap-2">
                    <Label :for="`question-${index}`"
                        >Question {{ index + 1 }}</Label
                    >
                    <Input
                        :id="`question-${index}`"
                        v-model="row.label"
                        maxlength="150"
                        placeholder="Where do you usually drive?"
                    />
                    <InputError :message="error(index, 'label')" />
                </div>
                <div class="flex flex-wrap items-center gap-4">
                    <div class="grid gap-2">
                        <Label :for="`question-type-${index}`">Answer</Label>
                        <select
                            :id="`question-type-${index}`"
                            v-model="row.type"
                            :class="selectClass"
                        >
                            <option
                                v-for="t in types"
                                :key="t.value"
                                :value="t.value"
                                :selected="row.type === t.value"
                            >
                                {{ t.label }}
                            </option>
                        </select>
                    </div>
                    <label
                        :for="`question-required-${index}`"
                        class="flex items-center gap-2 self-end pb-2 text-sm"
                    >
                        <Checkbox
                            :id="`question-required-${index}`"
                            :aria-label="`Question ${index + 1} is required`"
                            :model-value="row.required"
                            @update:model-value="
                                (v) => (row.required = v === true)
                            "
                        />
                        Required
                    </label>
                    <div class="ml-auto flex gap-1 self-end">
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            :disabled="index === 0"
                            :aria-label="`Move question ${index + 1} up`"
                            @click="move(index, -1)"
                            ><ArrowUp class="size-4"
                        /></Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            :disabled="index === form.rows.length - 1"
                            :aria-label="`Move question ${index + 1} down`"
                            @click="move(index, 1)"
                            ><ArrowDown class="size-4"
                        /></Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            :aria-label="`Remove question ${index + 1}`"
                            @click="form.rows.splice(index, 1)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                </div>
                <InputError :message="error(index, 'type')" />
                <div v-if="row.type === 'choice'" class="grid gap-2">
                    <Label :for="`question-options-${index}`"
                        >Options (one per line, 2 to {{ MAX_OPTIONS }})</Label
                    >
                    <textarea
                        :id="`question-options-${index}`"
                        v-model="row.optionsText"
                        rows="4"
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                    <InputError :message="error(index, 'options')" />
                </div>
            </fieldset>

            <InputError :message="listError()" />

            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.rows.length >= MAX"
                    @click="add"
                    ><Plus class="size-4" /> Add a question</Button
                >
                <Button type="submit" :disabled="form.processing"
                    >Save questions</Button
                >
            </div>
        </form>
    </section>
</template>
