<?php
/**
 * Sharek v1.5 - 500 Error Page
 * 
 * @file 500.php
 * @date 2026-05-25
 * @description Custom 500 Internal Server Error page
 * @version 1.5.0
 */

// Send a real 500 status (audit finding #35), same reasoning as 404.php.
http_response_code(500);
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - هەڵەی سێرڤەر | شەریک</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/responsive-fixes.css">
</head>
<body>
    <div class="error-page">
        <div class="error-container">
            <h1 class="error-code">500</h1>
            <h2 class="error-title">هەڵەی سێرڤەر</h2>
            <p class="error-message">ببورە، هەڵەیەک ڕوویدا. تکایە دواتر هەوڵبەرەوە.</p>
            <a href="index.html" class="btn btn-primary">گەڕانەوە بۆ سەرەوە</a>
        </div>
    </div>
    <style>
        .error-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-page);
        }
        .error-container {
            text-align: center;
            padding: 2rem;
        }
        .error-code {
            font-size: 8rem;
            font-weight: 900;
            color: var(--danger);
            line-height: 1;
            margin-bottom: 1rem;
        }
        .error-title {
            font-size: 2rem;
            color: var(--text-h);
            margin-bottom: 1rem;
        }
        .error-message {
            font-size: 1.1rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
        }
        .btn-primary {
            display: inline-block;
            padding: 0.75rem 2rem;
            background: var(--navy);
            color: white;
            text-decoration: none;
            border-radius: var(--r-md);
            transition: background 0.2s;
        }
        .btn-primary:hover {
            background: var(--navy-mid);
        }
    </style>
</body>
</html>
