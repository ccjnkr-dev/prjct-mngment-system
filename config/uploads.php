<?php

$platformLimit = env('VERCEL') ? 4096 : 102400;

return [
    'max_kilobytes' => max(1, min($platformLimit, (int) env('UPLOAD_MAX_KB', $platformLimit))),
];
