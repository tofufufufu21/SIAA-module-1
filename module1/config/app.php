<?php
// config/app.php
return [
    'upload_dir'    => __DIR__ . '/../public/uploads/assets/',
    'upload_url' => '/module1/public/uploads/assets/',
    'max_file_size' => 10 * 1024 * 1024, // 10 MB
    'allowed_mime'  => [
        'image/jpeg','image/png','image/gif','image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain','text/csv',
    ],
    'current_user_id' => 1, // Replace with session-based auth
];
