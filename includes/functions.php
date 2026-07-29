<?php

/**
 * Redirects the user to another page.
 *
 * @param string $location The destination page.
 */
function redirect($location)
{
    header("Location: " . $location);
    exit();
}
/**
 * Cleans user input.
 *
 * @param string $data
 * @return string
 */
function sanitizeInput($data)
{
    return htmlspecialchars(trim($data));
}
/**
 * Checks if the request method is POST.
 *
 * @return bool
 */
function isPostRequest()
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}