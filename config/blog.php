<?php

return [
    /*
    | Post drafts written in the repo (#5153): each `<Y-m-d>-<slug>.php` file in this folder
    | becomes a draft in Admin → Blog posts once (`App\Support\PostDrafts`). E2E points it at
    | `tests/fixtures/posts`, so production never gets a test draft.
    */
    'drafts_path' => env('BLOG_DRAFTS_PATH') ?: resource_path('posts'),
];
