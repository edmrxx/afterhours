<script setup>
/*
| Skeleton — a single shimmer block. Pair with sizing utilities:
|
|   <Skeleton class="h-4 w-32" />
|   <Skeleton rounded="full" class="h-10 w-10" />
|   <Skeleton :lines="3" />
*/

const props = defineProps({
    /** none | sm | md | lg | xl | full */
    rounded: { type: String, default: 'md' },
    /** Render N stacked lines of decreasing width instead of one block. */
    lines: { type: Number, default: 0 },
    /** Tailwind height utility applied to each line when `lines` is used. */
    lineHeight: { type: String, default: 'h-3.5' },
});

const ROUNDED = {
    none: 'rounded-none',
    sm: 'rounded',
    md: 'rounded-md',
    lg: 'rounded-lg',
    xl: 'rounded-xl',
    full: 'rounded-full',
};

const LINE_WIDTHS = ['w-full', 'w-11/12', 'w-9/12', 'w-10/12', 'w-8/12'];
</script>

<template>
    <div v-if="lines > 0" class="space-y-2" aria-hidden="true">
        <div
            v-for="n in lines"
            :key="n"
            :class="[
                'skeleton',
                props.lineHeight,
                ROUNDED[props.rounded] ?? ROUNDED.md,
                LINE_WIDTHS[(n - 1) % LINE_WIDTHS.length],
            ]"
        />
    </div>

    <div
        v-else
        :class="['skeleton', ROUNDED[props.rounded] ?? ROUNDED.md]"
        aria-hidden="true"
    />
</template>
