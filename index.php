<?php
session_start();
require 'config.php';

// Check if the user is already logged in
if (isset($_SESSION['user_id']) || isset($_COOKIE['rememberMe'])) {
    // User is logged in, redirect to main.php
    header('Location: main.php');
    exit();
} else {
    // User is not logged in, redirect to login.php
    header('Location: login.php');
    exit();
}
?>
