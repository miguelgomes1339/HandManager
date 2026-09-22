<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'handmanager';
const DB_USER = 'root';
const DB_PASS = ''; // XAMPP por defeito. Se tiver password, altera aqui.
const APP_NAME = 'HandManager';
const APP_SEASON = '2026/2027';

// Login com Google (Google Cloud Console > Credentials > OAuth 2.0 Client IDs)
// Para localhost, adiciona como URI de redirecionamento autorizada:
// http://localhost/HandManager/google_callback.php
const GOOGLE_CLIENT_ID = '908698206140-bk0crfn4ert303bujo2et5o59vq70hd2.apps.googleusercontent.com';
const GOOGLE_CLIENT_SECRET = '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';
const GOOGLE_REDIRECT_URI = 'http://localhost/handmanager/google_callback.php';
