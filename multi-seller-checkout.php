<?php
// Redirect to the new location in paymongo folder
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$redirectUrl = 'paymongo/multi-seller-checkout.php';
if ($queryString) {
    $redirectUrl .= '?' . $queryString;
}
header("Location: " . $redirectUrl);
exit();
?>
